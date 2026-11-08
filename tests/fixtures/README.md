# Fixtures

Real responses from the NEAR Intents 1Click API (mainnet), recorded on 2026-11-07. No funds were sent.

| File | Request |
|---|---|
| `quote-live-base-eth.json` | `POST /v0/quote`, `dry:false`, EXACT_OUTPUT: ETH on Base -> 5 USDC on NEAR |
| `quote-live-base-usdc.json` | `POST /v0/quote`, `dry:false`, EXACT_OUTPUT: USDC on Base -> 5 USDC on NEAR |
| `quote-dry-base-eth.json` | `POST /v0/quote`, `dry:true`, same as the first |
| `status-live-pending.json` | `GET /v0/status` for the deposit address of `quote-live-base-eth.json` (PENDING_DEPOSIT) |

All four carry a 1Click ed25519 signature that the official TypeScript SDK
(`@defuse-protocol/one-click-sdk-typescript` 0.1.26, `verifyQuoteSignature`) accepts.

`tests/unit/StatusEvaluatorTest.php` derives the other statuses (SUCCESS, REFUNDED, ...) from the
real PENDING response by changing only `status` and `swapDetails`; those variants are synthetic and
the test says so.
