<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

use RuntimeException;

/** Thrown on transport/HTTP failures and malformed wallet/transaction input. */
final class ArweaveException extends RuntimeException {}
