# NMI sandbox check (spec 0012, step 0)

Run on 2026-11-13 against the sandbox merchant, with the v5 private key read from the local `.env` (never printed). The dummy token `00000000-000000-000000-000000000000` works in the sandbox, so server calls can be tested without a browser. No key values are in this file.

## Answered

| # | Question | Answer |
|---|---|---|
| 1 | Sandbox host, and does `query.php` take the v5 key? | Sandbox host is `https://sandbox.nmi.com`. `secure.nmi.com` answers a sandbox key with "Sandbox accounts must use a sandbox domain". `query.php` works with the v5 private key. |
| 2 | v5 sale reply shape | HTTP 200 with `object`, `id`, `type`, `amount`, `currency`, `auth_code`, `avs_response`, `cvv_response`, `status`, `response`, `response_text`, `response_code`, `processor_id`, `payment_details`, `order_details`, `actions[]`. Approved: `response_code` "100", `status` `pendingsettlement`. A decline is also HTTP 200: `response_code` "200", `response_text` "DECLINE", `status` `failed` (sandbox amounts 0.01 and 0.99 decline). Card detail codes still to confirm. |
| 3 | `query.php` fields | By `transaction_id` or `order_id`. Returns `transaction_id`, `condition` (`pendingsettlement`), `order_id`, `currency` (**yes, returned**), `cc_number`, `csc_response`, `avs_response`, 3DS fields. No match returns an empty `<nm_response>`. |
| 4 | Tokenization key format | Yes, dashed `xxxxxx-xxxxxx-xxxxxx-xxxxxx`. |
| 8 | Double charge guard | No idempotency header (a repeat with `Idempotency-Key` is still a normal sale). NMI does reject an identical repeat with HTTP 400 `E_DUPLICATE` "Duplicate transaction". Treat `E_DUPLICATE` as **unclear** (a sale may already exist), then look up. |
| 9 | 3DS field names | `cardholder_auth` object: `cavv`, `xid`, `eci`, `cardholder_auth`, `three_ds_version`, `directory_server_id`. |
| 12 | Bad key vs empty result | Distinct. Bad key: `<error_response>Specified API key not found</error_response>`. Unknown order: empty `<nm_response>`. |

## Corrections to the spec

- **Order id**: the v5 sale rejects top level `order_id`. The field is **`order_details.id`**, and `query.php` returns it as `<order_id>` and finds it by `order_id`. (`order_details.description` and `po_number` do not fill `order_id`.)
- **Name and email**: sent in a `billing_address` object (`first_name`, `last_name`, `email`), not at the top level. Not yet confirmed that `email` is accepted there.
- **Errors**: request problems are HTTP 400 with `type` and `error_code` (`E_INVALID_SUBMISSION`, `E_GENERIC_ERROR`, `E_DUPLICATE`). An invalid or used token is `E_GENERIC_ERROR` "Invalid payment token" (an `error`, not a decline).
- **GBP**: this sandbox processor answers "cc payment type [Visa] and/or currency [GBP] is not accepted". GBP cannot be tested here until Beyond Bancard or the portal enables it.
- **Sale reply has no top level order id**: the reply `order_details` holds `ip_address`, `tax`, `shipping`. Use `query.php` for the order id check.

## Still open (needs a real browser run of the Payment Component)

- 5. Google Pay hosts, and whether it works in the sandbox.
- 6. 3DS `onComplete` result shape, and how the sale reply shows a 3DS result.
- 7. Webhook `event_body.order_id` (needs a webhook registered in the portal).
- 10. Whether AVS or 3DS rules need a ZIP.
- 11. 3DS challenge frame hosts for the CSP.
- Which `response_code` values mean card detail declines (try test cards in the portal).
