// Generates golden.json from arweave-js — the reference native-L1 implementation.
//
// Dev-only: arweave-js is NOT a runtime dependency of agentimprint/arweave-php. Run
// once to (re)capture fixtures; the PHP parity suite asserts byte-for-byte equality
// against these values (this is the package's correctness gate before any mainnet use):
//
//   cd packages/arweave-php/tools && npm install && node generate-golden.cjs > ../tests/fixtures/golden.json
//
// Only PUBLIC material is emitted (owner modulus, signatures, ids, serialized JSON).
// The ephemeral RSA private key used to sign is generated here and discarded — it is
// never written to the fixtures, so no wallet secret is committed.

const Arweave = require('arweave');
const deepHash = require('arweave/node/lib/deepHash.js').default;
const { generateTransactionChunks } = require('arweave/node/lib/merkle.js');

const arweave = Arweave.init({ host: 'localhost', port: 1984, protocol: 'http' });
const U = Arweave.utils;
const b64url = (b) => U.bufferTob64Url(new Uint8Array(b));
const hex = (b) => Buffer.from(b).toString('hex');

// Deterministic byte pattern shared with the PHP test (byte[i] = i % 256).
function pattern(size) {
  const buf = Buffer.alloc(size);
  for (let i = 0; i < size; i++) buf[i] = i % 256;
  return buf;
}

async function txVector(label, dataStr, tags, reward, lastTx, wallet, owner) {
  const tx = await arweave.createTransaction({ data: Buffer.from(dataStr, 'utf8'), reward, last_tx: lastTx }, wallet);
  tags.forEach((t) => tx.addTag(t.name, t.value));
  const sigData = await tx.getSignatureData();
  await arweave.transactions.sign(tx, wallet);
  return {
    label,
    data_utf8: dataStr,
    tags,
    reward,
    last_tx: lastTx,
    owner_b64url: owner,
    signatureMessage_hex: hex(sigData),
    data_root: tx.data_root,
    data_size: tx.data_size,
    signature_b64url: tx.signature,
    id: tx.id,
    toJSON: JSON.stringify(tx.toJSON()),
  };
}

async function dataRootVector(label, size) {
  const data = pattern(size);
  const { data_root, chunks, proofs } = await generateTransactionChunks(data);
  return {
    label,
    size,
    data_root: b64url(data_root),
    chunk_count: chunks.length,
    proof_count: proofs.length,
  };
}

// Multi-chunk POST /chunk upload bodies, captured from arweave-js's own `tx.getChunk(i)`
// — the exact wire fields (data_root, data_size, data_path/proof bytes, offset) uploaded
// for a multi-chunk transaction. This is the offline parity target for
// SignedTransaction::chunkProofs(); a subtle data_path/proof bug otherwise only
// surfaces as a gateway-rejected chunk upload (data never persists → breaks perpetuity).
//
// The `chunk` payload itself is intentionally NOT captured here: it is just
// b64url(data.slice(minByteRange, maxByteRange)) of the deterministic test pattern,
// which both implementations compute identically and the ArLocal round-trip exercises.
// Omitting it keeps the committed fixture small while still pinning the hard part —
// the Merkle `data_path` proof bytes — byte-for-byte against arweave-js.
async function chunkUploadVector(label, size, wallet) {
  const data = pattern(size);
  const tx = await arweave.createTransaction({ data, reward: '1', last_tx: '' }, wallet);
  await tx.prepareChunks(data);
  const uploads = [];
  for (let i = 0; i < tx.chunks.chunks.length; i++) {
    const { chunk, ...wire } = tx.getChunk(i, data); // drop bulky chunk payload, keep proof fields
    uploads.push(wire);
  }
  return {
    label,
    size,
    data_root: tx.data_root,
    data_size: tx.data_size,
    chunk_count: tx.chunks.chunks.length,
    uploads,
  };
}

// Multi- vs single-chunk POST /tx body, captured exactly as arweave-js's
// TransactionUploader.postTransaction serializes it: a multi-chunk tx (totalChunks >
// MAX_CHUNKS_IN_BODY === 1) posts `data:""` and uploads bytes via POST /chunk; only a
// single-chunk tx inlines its data. This is the offline parity target for
// SignedTransaction::toGatewayJson() — the bug where a multi-chunk body inlined full
// data (gateway-rejected → data never persists) survived a green offline suite because
// nothing pinned the posted-body bytes. `post_body` is the JSON the uploader would POST.
async function gatewayBodyVector(label, size, tags, reward, lastTx, wallet, owner) {
  const data = pattern(size);
  const tx = await arweave.createTransaction({ data, reward, last_tx: lastTx }, wallet);
  tags.forEach((t) => tx.addTag(t.name, t.value));
  await arweave.transactions.sign(tx, wallet);
  await tx.prepareChunks(data);
  const chunkCount = tx.chunks.chunks.length;

  // Replicate the uploader: inline data only when totalChunks <= MAX_CHUNKS_IN_BODY (1).
  const fullData = tx.data;
  if (chunkCount > 1) tx.data = new Uint8Array(0);
  const postBody = JSON.stringify(tx.toJSON());
  tx.data = fullData;

  return {
    label,
    size,
    tags,
    reward,
    last_tx: lastTx,
    owner_b64url: owner,
    signature_b64url: tx.signature,
    id: tx.id,
    data_root: tx.data_root,
    data_size: tx.data_size,
    chunk_count: chunkCount,
    is_multi_chunk: chunkCount > 1,
    post_body: postBody,
  };
}

(async () => {
  const wallet = await arweave.wallets.generate();
  const owner = wallet.n; // modulus, base64url — the tx "owner" (public)
  const address = await arweave.wallets.jwkToAddress(wallet);

  const out = { wallet: { owner_b64url: owner, address }, deepHash: [], dataRoot: [], chunkUploads: [], gatewayBodies: [], transactions: [] };

  // deep-hash vectors (key-independent)
  out.deepHash.push({ label: 'blob_hello', input_utf8: 'hello', out_hex: hex(await deepHash(Buffer.from('hello', 'utf8'))) });
  out.deepHash.push({ label: 'empty_blob', input_utf8: '', out_hex: hex(await deepHash(Buffer.from('', 'utf8'))) });
  out.deepHash.push({ label: 'list_two', desc: '["abc","de"]', out_hex: hex(await deepHash([Buffer.from('abc'), Buffer.from('de')])) });
  out.deepHash.push({ label: 'nested', desc: '["x",["y","z"]]', out_hex: hex(await deepHash([Buffer.from('x'), [Buffer.from('y'), Buffer.from('z')]])) });

  // data_root vectors across the chunk boundaries (MAX=256KiB, MIN=32KiB)
  out.dataRoot.push(await dataRootVector('empty', 0));
  out.dataRoot.push(await dataRootVector('tiny', 5));
  out.dataRoot.push(await dataRootVector('one_byte_under_max', 256 * 1024 - 1));
  out.dataRoot.push(await dataRootVector('exactly_max', 256 * 1024));
  out.dataRoot.push(await dataRootVector('just_over_max', 256 * 1024 + 1)); // triggers rebalance (rest < MIN)
  out.dataRoot.push(await dataRootVector('two_full_chunks', 512 * 1024));
  out.dataRoot.push(await dataRootVector('multi_chunk', 600 * 1024)); // 3 chunks
  out.dataRoot.push(await dataRootVector('large_multi_chunk', 1024 * 1024 + 7)); // 5 chunks, remainder

  // multi-chunk POST /chunk upload bodies (data_path/proof byte-parity target)
  out.chunkUploads.push(await chunkUploadVector('multi_chunk', 600 * 1024, wallet)); // 3 chunks
  out.chunkUploads.push(await chunkUploadVector('large_multi_chunk', 1024 * 1024 + 7, wallet)); // 5 chunks, remainder

  const anchor = b64url(Buffer.from('anchor-bytes-fixed-for-golden-vectors-0001'.padEnd(48, '!')));

  // POST /tx body bytes — the data-inlining gate. The multi-chunk vectors MUST serialize
  // with data:"" (bytes go via POST /chunk); the single-chunk vector keeps data inline.
  const bodyTags = [{ name: 'App', value: 'AgentImprint' }, { name: 'Encrypted', value: 'true' }];
  out.gatewayBodies.push(await gatewayBodyVector('multi_chunk_zeroed', 600 * 1024, bodyTags, '1000000', anchor, wallet, owner)); // 3 chunks
  out.gatewayBodies.push(await gatewayBodyVector('large_multi_chunk_zeroed', 1024 * 1024 + 7, bodyTags, '777', anchor, wallet, owner)); // 5 chunks
  out.gatewayBodies.push(await gatewayBodyVector('single_chunk_inline', 5, bodyTags, '512', anchor, wallet, owner)); // 1 chunk, inline

  // transaction vectors (owner + signature are this ephemeral key's; verified via modulus)
  out.transactions.push(await txVector('four_tags', 'imprint-bundle-golden-vector-001',
    [{ name: 'App', value: 'AgentImprint' }, { name: 'Vault', value: 'vault-uuid-1234' },
     { name: 'Content-Type', value: 'application/json' }, { name: 'Encrypted', value: 'true' }],
    '1000000', anchor, wallet, owner));
  out.transactions.push(await txVector('no_tags', 'no-tags-payload', [], '512', anchor, wallet, owner));
  out.transactions.push(await txVector('unicode_tag', 'tag-with-unicode', [{ name: 'Ünïcödé', value: 'välüé-✓' }], '777', anchor, wallet, owner));
  out.transactions.push(await txVector('empty_data', '', [{ name: 'k', value: 'v' }], '0', anchor, wallet, owner));

  console.log(JSON.stringify(out, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
