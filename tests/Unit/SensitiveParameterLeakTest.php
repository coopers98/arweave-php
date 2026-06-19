<?php

declare(strict_types=1);

/*
 * Regression for 3x3 R2 IMP-1: the decoded private JWK must never reach a
 * structured exception trace, even with zend.exception_ignore_args=Off (the
 * setting a trace-logging framework would use). `#[\SensitiveParameter]` on the
 * Wallet and RsaPss constructors redacts the $jwk argument to a
 * SensitiveParameterValue, which serialises to `{}` rather than the key bytes.
 *
 * The check MUST run in a child PHP process: ignore-args is read at startup, so
 * the parent (Pest) process cannot toggle it. We spawn `php -d
 * zend.exception_ignore_args=Off`, trigger the two leak-prone paths, and assert
 * the child's stdout — which dumps json_encode($e->getTrace()) for each — carries
 * a recognisable sentinel embedded in the private JWK fields and NONE of it
 * survives into the trace.
 */

/** A sentinel only ever placed in PRIVATE JWK fields, so any appearance in a trace is a leak. */
const SENTINEL = 'S3NTIN3L_PRIVATE_KEY_zZ9_DO_NOT_LEAK';

/**
 * The child program: requires the autoloader (argv[1]), drives both leak paths,
 * and prints the resulting exception message + json-encoded trace for each.
 */
function leakProbeChildSource(): string
{
    $sentinel = SENTINEL;

    return <<<PHP
    <?php
    declare(strict_types=1);
    require \$argv[1];

    use AgentImprint\\Arweave\\Wallet;
    use AgentImprint\\Arweave\\ArweaveException;
    use AgentImprint\\Arweave\\Crypto\\RsaPss;

    \$dump = static function (string \$label, \\Throwable \$e): void {
        echo \$label, "::MSG::", \$e->getMessage(), "\\n";
        echo \$label, "::TRACE::", json_encode(\$e->getTrace()), "\\n";
    };

    // (a) Wallet validation failure: all fields present EXCEPT qi, so it throws in the
    // required-field loop while the private fields (d/p/q/...) are the ctor argument.
    try {
        new Wallet([
            'kty' => 'RSA',
            'n'   => 'modulus-not-checked-here',
            'e'   => 'AQAB',
            'd'   => '{$sentinel}_D',
            'p'   => '{$sentinel}_P',
            'q'   => '{$sentinel}_Q',
            'dp'  => '{$sentinel}_DP',
            'dq'  => '{$sentinel}_DQ',
            // 'qi' deliberately omitted -> throws "missing the RSA parameter \"qi\"".
        ]);
        echo "WALLET::NOTHROW\\n";
    } catch (ArweaveException \$e) {
        \$dump('WALLET', \$e);
    }

    // (b) RsaPss load failure: passes the shape gate (kty=RSA, n+d set, e=AQAB) but is
    // not a loadable RSA key, so phpseclib throws inside the ctor and we reach the catch.
    try {
        new RsaPss([
            'kty' => 'RSA',
            'e'   => 'AQAB',
            'n'   => 'UNLOADABLE-MODULUS',
            'd'   => '{$sentinel}_D',
            'p'   => '{$sentinel}_P',
            'q'   => '{$sentinel}_Q',
        ]);
        echo "RSAPSS::NOTHROW\\n";
    } catch (ArweaveException \$e) {
        \$dump('RSAPSS', \$e);
    }
    PHP;
}

/**
 * Run the probe in a fresh PHP process with exception arg-capture FORCED ON.
 *
 * @return array{stdout: string, stderr: string, code: int}
 */
function runLeakProbe(): array
{
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = tempnam(sys_get_temp_dir(), 'arweave-leak-probe-').'.php';
    file_put_contents($script, leakProbeChildSource());

    $php = PHP_BINARY ?: 'php';
    $cmd = sprintf(
        '%s -d zend.exception_ignore_args=Off %s %s 2>&1',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($autoload),
    );

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $code = proc_close($proc);

    @unlink($script);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
}

test('private JWK never leaks into exception trace args even with arg capture forced on', function () {
    $result = runLeakProbe();

    // The probe must have actually reached both throwing paths (not silently no-op'd).
    expect($result['stdout'])
        ->toContain('WALLET::MSG::')
        ->and($result['stdout'])->toContain('RSAPSS::MSG::')
        ->and($result['stdout'])->not->toContain('WALLET::NOTHROW')
        ->and($result['stdout'])->not->toContain('RSAPSS::NOTHROW');

    // We hit the intended branches: Wallet's missing-qi guard and RsaPss's load catch.
    expect($result['stdout'])
        ->toContain('qi')
        ->and($result['stdout'])->toContain('Failed to load the RSA JWK into phpseclib.');

    // The whole point: no private sentinel survives anywhere in the child output,
    // which includes json_encode($e->getTrace()) for both exceptions.
    expect($result['stdout'])->not->toContain(SENTINEL);
});
