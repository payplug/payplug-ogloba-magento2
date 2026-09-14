# Payplug Ogloba Module for Magento 2

Ogloba gift card payment method (`payplug_payments_ogloba`) for Magento 2.4.x / Adobe Commerce.

Built on the official Magento payment gateway architecture: the method is a
`Magento\Payment\Model\Method\Adapter` virtual type (`PayplugPaymentsOglobaAdapter`) configured entirely
through `etc/di.xml` — no deprecated `AbstractMethod` inheritance.

## Configuration

Admin: **Stores → Configuration → Sales → Payment Methods → PayPlug Ogloba Gift Card**

Config path prefix: `payment/payplug_payments_ogloba/`

| Field | Path | Default |
|---|---|---|
| Enabled | `active` | `0` |
| Title | `title` | Ogloba Gift Card |
| Description | `description` | Pay for your order with your Ogloba gift card. |
| New Order Status | `order_status` | `pending` |
| Country restriction | `allowspecific` / `specificcountry` | all countries |
| Min/Max order total | `min_order_total` / `max_order_total` | — |
| Sort order | `sort_order` | `10` |

Enable via CLI:

```bash
bin/magento config:set payment/payplug_payments_ogloba/active 1
```

## Structure

- `etc/di.xml` — gateway facade (`PayplugPaymentsOglobaAdapter`), value handler pool, validator pool
- `Gateway/Config/Ogloba.php` — typed accessor over `payment/payplug_payments_ogloba/*`
- `Model/Ui/ConfigProvider.php` — exposes the description to `window.checkoutConfig`
- `Service/` — one class per action, each exposing a single `execute()`. `ResolvePayment` and
  `ProcessReturn` orchestrate and are what the controllers call; `GetPaymentResponse`,
  `IsOrderPaid`, `IsOrderAwaitingPayment`, `IsCurrencySupported`, `MarkOrderAsProcessing`,
  `CancelOrder`, `OrderLockAcquire`, `OrderLockRelease`, `SendOrderEmail` and `BuildHistoryComment`
  do one thing each. A collaborator
  that serves a single class stays a private method of it, unless splitting it apart from its
  counterpart would read worse than keeping the pair together.
- `Model/Payment/PaymentResponse.php` — the signed verdict the services act on
- `Api/Data/` — the shared constants: the response keys and statuses, the settlement channels, the
  outcomes, the payment additional information keys, the lock name and timeout
- `view/frontend/` — checkout renderer registration (layout + JS components + KO template)
- `i18n/` — en_US, fr_FR, es_ES and it_IT translations

## Logging

Everything goes to `var/log/payplug_ogloba.log`. What happened stays at `info`, `warning` or `error`
and is always written: an order settled, a signature refused, a status that needs a human.

The **payloads** — the request sent to Ogloba and its response, the body of a notification, the whole
of a customer return request — are `debug` and only reach the log when **Debug Mode** is on, which is
what the setting has always said it does. `Logger\Handler` decides, so no caller carries the
configuration around. They are off by default for two reasons: they are verbose, and they carry the
`validateCode` that identifies a transaction.

A **refused signature** is the one exception: the source IP and the payload received are written at
`error`, Debug Mode or not, because that is the only record a rejected call can be investigated from.
Those payloads carry Ogloba references and a status, never customer data.

The module logs through `PayplugPaymentsOglobaLogger`, a `Magento\Framework\Logger\Monolog` virtual
type whose `system` handler is `Logger\Handler`, injected as `Psr\Log\LoggerInterface` through the
`logger` argument of every class that logs: the pattern the Adobe Commerce documentation describes. A
class that logs must have that argument set in `etc/di.xml`, otherwise Magento hands it its main logger
and its records never reach `payplug_ogloba.log`.

Like any virtual type, it inherits the other handlers configured on `Magento\Framework\Logger\Monolog`,
arrays being merged item by item. The records therefore also reach `debug.log` when Magento's debug
logging is on, and `support_report.log` where `Magento_Support` is installed, payloads included: Debug
Mode only governs `payplug_ogloba.log`. Taking the `system` slot keeps them out of `system.log`.

### Reading the log from the back office

**Sales → Ogloba API Logs** shows the end of that same file, the most recent line first, so the
support teams read the API exchanges without a shell. It is a reader and nothing else: nothing is
written, and there is no second copy of the log to keep in sync — what the page shows is the file.

Only the last 2 MB are ever read, whatever the size the file has grown to, and the requested number
of lines (100 to 2000) is taken from that tail. A filter narrows it down to the lines holding a given
string, an order number or an Ogloba operation more often than not. Errors and warnings are coloured.

The page has its own ACL resource, `Payplug_Ogloba::log`, so it can be granted to the support role
alone. When Debug Mode is off on every store view, a notice says so and points at the payment
configuration: what happened is in the log, the payloads are not.

## Payment outcome

Ogloba settles a transaction twice: through the notification (`payplug_ogloba/payment/ipn`, server to
server) and through the customer return (`payplug_ogloba/payment/paymentReturn`, browser).

Both carry the very same signed response. The notification posts it as a JSON body, the return passes
it on the query string:

```
GET /payplug_ogloba/payment/paymentReturn/?response=<url encoded json>&sign=<hmac sha256>
```

```json
{"responseCode":"101","responseLabel":"The payment was cancelled and not successful.",
 "reference":"2802","brand":"Ogloba","paymentMethodOrderId":"2802",
 "paymentMethodOperationId":"5675b0e6ed1241fa95e6d2cc3e850f21","orderStatus":"Cancelled"}
```

The `response` object and its signature are byte for byte the same on both channels — only the
envelope differs, an object nested in the body against a JSON string on the query. Both are therefore
read through one value object, and `Service/ResolvePayment` applies the verdict whichever channel brought
it: same signature check, same lock, same order lookup by `paymentMethodOperationId` (which is the
`validateCode` the order was created with), same state transitions.

Only the raw string the return carries is signed as such, so the signature is checked against it
without re-encoding; the notification, which nests the object, is checked against a faithful
re-encoding. The return reader also accepts a JSON body and flat parameters in case the format ever
changes.

### Payment order statuses

Ogloba answers with one of the ten statuses of the
[Thunes payment order reference](https://collection-docs.thunes.com/reference/payment-order-status),
four of which are **not final** — the transaction can still change status afterwards, so they must
never cancel an order:

| `orderStatus` | Order |
|---|---|
| `Created`, `Payment_In_Progress`, `Authorizing` | untouched, left open to a later verdict |
| `Authorized` | not supported, see below |
| `Charged` | processing, queued for invoicing |
| `Aborted`, `Refused`, `Error`, `Cancelled` | cancelled |
| anything else, including a status Ogloba may add later | cancelled |

**`Authorized` is deliberately out of scope for this version.** Ogloba would have secured the amount
without taking it, which needs an authorise-then-capture flow this module does not implement. Should
the status arrive anyway, the order is neither released nor cancelled: cancelling would throw away a
payment about to succeed, releasing it would ship goods that are not paid for. It is left waiting for
the `Charged` or `Cancelled` that the reference says follows, with a history comment saying the status
is unsupported and an **error** in the log so it surfaces. If no final status ever comes, the order
stays pending payment and needs a human.

`Refunded` and `Refunding` answer a refund. On an order the module has already resolved they only
update its refund status, see [Refunds](#refunds); on an order still awaiting payment they are no
payment, and fall through to cancellation.

Every status, the non-final ones included, writes an order history comment: the channel, the raw
status, the response code and the description that status maps to. Ogloba's own `responseLabel` is
deliberately left out — it says the same thing in untranslatable English, and the raw payload is
logged in full anyway. A second sentence is added only where the status does not already say what
became of the order:

```
Ogloba notification - Charged (code 100): The whole amount has been charged on the gift card.
Ogloba notification - Payment_In_Progress (code 107): The customer is entering their payment details. Not a final status, the order is left as it is.
Ogloba notification - Authorized (code 108): The amount is secured but has not been charged. Not supported by this version, the order awaits a final status.
Ogloba notification - SomeNewStatus (code 999): Unknown status, handled as a failed transaction.
```

`Charged`, `Aborted`, `Refused`, `Error`, `Cancelled` and `Refunded` get no added sentence: the status
and the order status column already tell the story. A status Ogloba adds later is reported as
unrecognised. These comments are admin side only, and ship translated in the four `i18n` CSVs like
the rest of the module's wording, back office included.

Beware that this reference documents Thunes Collections, while the gift card gateway answers on
`gc-thunes-rgw`: a customer-side cancellation was observed as `Cancelled`, where the reference reserves
that value for a merchant-side one and expects `Aborted`. The enumeration is the right family, the
exact mapping of this gateway is not contractual.

**What differs is what can be proven.** The notification reaches the server directly. The return
travels through the customer's browser, so it is only acted upon once its signature is verified *and*
the operation it names is the one of the order in session — otherwise a replayed return would settle
one order while the cart of another is rolled back. Anything unproven is a mere hint and only the
order state decides:

| Situation | Order | Redirect |
|---|---|---|
| Return proven, `orderStatus` is `Charged` | processing | success |
| Return proven, any other status | cancelled | cart + quote restored |
| Return unproven, notification already settled it as paid | **untouched** | success |
| Return unproven, notification already cancelled it | **untouched** | cart + quote restored |
| Return unproven, claiming a payment | **untouched** | success |
| Return unproven, claiming anything else, or empty | **untouched** | success, with a pending notice |

Every return, proven or not, notes in the order history that the customer came back from the payment
page. That is the one write the return makes, and it settles nothing.

**Beyond it, an unproven return never touches the order.** Not the state, not the status, not the
payment information. It only decides where to send the customer, and restores the quote in the session
only where the failure is proven. The notification settles the order, as it always does, whenever it
arrives.

**An unproven return never restores the cart.** Handing the cart back would invite a second payment for
an order the notification may still settle as paid, so the customer is sent to the order confirmation
with a notice saying the payment is still being confirmed. The cost is borne by the opposite case: a
customer whose payment did fail keeps an order the notification will cancel, and builds a new cart
rather than finding theirs restored.

The message they see says only what is known. When the transaction is settled — proven by the return
itself, or already settled by the notification — it is stated plainly:

> The payment was not completed and your gift card has not been charged.

When nothing could be proven and the order is still pending, it promises nothing:

> Your order has been placed and the Ogloba payment is still being confirmed. Your order will be
> updated as soon as the confirmation arrives.

The order state is still read, never written, before that decision: when the notification has already
settled the order — which the observed traffic says is the common case, it lands a few hundred
milliseconds ahead of the browser — the redirect follows the settled order rather than an unverifiable
claim. Without that read, an unproven return landing on a settled order would promise a confirmation
still to come for a transaction that is in fact already resolved.

A `Charged` response landing on an order that was already cancelled is written in the order history
and logged as an error for manual review.

## Order view

`Block\Adminhtml\Info` fills the Payment Information box of the admin order view with the Ogloba
references of the transaction: payment status, refund status, the environment the order was sent to,
Ogloba order id, payment and last refund operation ids, order sequence, the two Limonetik ids the
module generated, and which channel settled it. A refund status of `Refunded` reads as partially
refunded while the credit memos cover only part of what was paid. Empty values are dropped, so a
transaction that never got a verdict shows only what it has.

These identifiers serve support and Ogloba reconciliation, not the customer, and two things keep them
out of what the customer receives. The block is wired in `etc/adminhtml/di.xml`, not in the global
`etc/di.xml`, so the storefront resolves `infoBlockType` to Magento's own block and never reaches this
one. And order emails and PDFs, which can be rendered from an admin request and would therefore get
the adminhtml wiring, both flag themselves through `setIsSecureMode()`, which the block honours.

`getIsSecureMode()` is deliberately **not** used as the test. In the admin order view nothing sets the
flag, so it falls back to comparing the payment method's store with the admin one — and the method
carries the order's store, which is never the admin store. It answers true in the back office, which
is the opposite of what the name suggests.

## Invoicing

A `Charged` settlement queues the order for invoicing on `payplug.ogloba.order.invoicing`, published
only once the settlement is committed. `Service\CreateInvoice` consumes it, in the same shape as
`Payplug_Payments` does for `payplug.order.invoicing`, on its own topic so the two never mix.

The capture case is `CAPTURE_OFFLINE`: Ogloba debits the gift card, Magento only records it. This
works with `can_capture=0` because `Invoice::register()` pays the invoice as soon as the requested
case is offline, whatever the gateway can capture — no payment capability needs changing, and
`can_invoice` is not a Magento payment config key at all. The Ogloba `validateCode` is carried over as
the invoice transaction id. The invoice email goes out through Magento's own `InvoiceSender`, which
honours `sales_email/invoice/enabled`; a failure there is logged and never fails the invoicing.

**`total_paid` is never the source of truth for whether the payment succeeded.** It trails the
settlement by however long the consumer takes to run, so a customer can be on the success page before
any invoice exists. What Ogloba answered is recorded synchronously, under the lock, in the payment's
`ogloba_order_status`. The invoice exists so the admin and the rest of Magento see a paid order, not
so the module can ask itself whether it was paid.

The consumer also records a Magento **capture transaction**, so the order's Transactions tab is not
empty and a later refund has something to work from. The Ogloba operation (`validateCode`) is its
transaction id, which reconciles the two sides and makes the builder idempotent: it reuses a
transaction with that id instead of adding a second one. It is closed — Ogloba debits the gift card in
one go, nothing is left to capture or void — and carries the settled status and channel as raw
details.

The consumer is idempotent — it reloads the order and gives up on `hasInvoices()` or `canInvoice()` —
which covers a redelivered message. It deliberately takes no lock: Magento runs one process per queue
unless `cron_consumers_runner/multiple_processes` says otherwise, and the settlement it follows is
already committed. Running several processes on this queue would require adding one.

Everything the return receives (method, URI, query, post, route params, body, referer) is written to
`var/log/payplug_ogloba.log`.

## Refunds

Refunds are issued from the back office with a credit memo on the invoice — **Refund**, not
**Refund Offline** — totally or partially, in as many credit memos as needed.
`Gateway\Command\RefundCommand` posts each one to `/gc-thunes-rgw/transaction/V1/refunds`, signed with
the HMAC key like every other call:

```json
{"request":{"limonetikOrderId":"q3k9z1v8m2x7c4b6n5l0p1a2",
 "limonetikOperationId":"h7d2w9s4k1f6j3m8r5t0y2u4","amount":{"value":"1.00","currency":"EUR"}},
 "sign":"<hmac sha256>"}
```

The `limonetikOrderId` is the one the module generated for the payment order and kept on the
payment. Every refund gets a `limonetikOperationId` of its own, but the Magento refund transaction
takes the `paymentMethodOperationId` Ogloba answers with, as the payment transaction takes the
`validateCode`: that is the id a later notification can name. The Limonetik one stands in when Ogloba
sends none. Ogloba answers in the body, the HTTP status being an answer as long as it stays below 400:

| `orderStatus` | Credit memo |
|---|---|
| `Refunded` | created, the refund transaction closed |
| `Refunding` | created, the refund transaction left open for the notification to confirm |
| anything else | not created, and the back office shows why |

A refused refund is reported in the back office and in the log with the status, the `responseCode`
with the meaning the Thunes Open Payment Connector specification gives it (100 OK, 101 Not Ok,
500 Technical Error, 501 Bad Credentials, 502 Insufficient Balance, 503 Timeout, 504 Bad Request,
505 Not Available) and Ogloba's own `responseLabel`:

```
Ogloba reported the refund of order 000000042 as Error (code 500 – Technical Error, label "10024"), the credit memo has not been created.
```

A created credit memo records `ogloba_refund_status` and `last_refund_operation_id` on the payment,
which the order view shows, and the order history names the amount and the operation. A later
notification reporting `Refunded` or `Refunding` on the order updates that status and the history,
and touches nothing else.

One reporting anything else on a refund still `Refunding` — `Aborted`, `Refused`, `Error`,
`Cancelled`, or a status the module does not know — says that refund did not go through. The credit
memo already exists and Magento cannot take it back, so the module records the status, writes an
error to the log and a history comment asking for a manual review. `Charged` or a status that is not
final says nothing about the refund and is ignored, as on any resolved order. No email goes out: a
refund is a back office action, and the order is where its outcome is read.

### Which merchant account

Every Ogloba call uses the configuration of the order's store, never the admin's. The refund request
names no merchant account, as the Ogloba specification has it: it is signed with the store's HMAC key,
sent to its host, and Ogloba finds the order from the `limonetikOrderId` alone, whichever of the
accounts sharing that host and key it was paid on. Adding a `merchantId` was tried: the gateway then
answers `501-Signature verification failed`, whatever its value.

Magento keeps no history of its configuration, and a store switched to another account since the
payment would sign the refund for the new one. So the payment records the references it was sent
with, which are no secrets — `environment` and `merchant_id` — and the refund is refused before
anything is sent when either no longer matches the store's configuration. Such an order is refunded
offline. Where the accounts share a key, this also refuses an order of the store's former account
that Ogloba would still refund. Orders paid before `merchant_id` was recorded carry none, and are sent
as the store is configured now.

The HMAC key is deliberately not recorded. It is a credential, not a fact about the transaction: a
refund must be signed with the key valid at the time of the call, so a rotated key applies at once,
and a copy of it on every order would spread a secret to every order row, the REST API and the
backups.

The refund is also refused when the payment carries no Limonetik order id, and when the order
currency differs from the base one, the amount Magento refunds being in the base currency.

## Order confirmation email

Magento announces an order the moment it is placed. `Magento\Quote\Observer\SubmitObserver` sends the
confirmation from `sales_model_service_quote_submit_success`, and only holds it back when the payment
method carries an `order_place_redirect_url` or when the order says not to. A redirected gift card
payment has neither by default, so the customer would be thanked for an order they have not paid, and
would keep that email when the transaction is refused a minute later and the order is cancelled.

So `Gateway\Command\CreatePaymentCommand` sets `can_send_new_email_flag` to false on the order it is
about to send to Ogloba — an in-memory flag, never persisted, that only that one observer reads — and
`Service\SendOrderEmail` sends the confirmation from `Service\ResolvePayment`, once the order is
committed as `Charged`, alongside the invoicing it queues. The same reasoning as `Payplug_Payments`,
which holds the email in `Gateway\Response\Standard\PaymentHandler` and sends it when the payment
resource comes back paid.

Which means the confirmation goes out **once**, when the payment is proven, and never for an order
that ends up cancelled:

| Resolution | Confirmation email |
|---|---|
| `Charged` | sent, from the channel that resolved the order |
| not final (`Created`, `Payment_In_Progress`, `Authorizing`, `Authorized`) | not sent, the order is not resolved yet |
| cancelled (`Aborted`, `Refused`, `Error`, `Cancelled`, unknown) | never sent |
| a resolved order receiving a second response | not sent again, `email_sent` is checked first |

`OrderSender` names the store of the order itself, so the email is rendered in the locale the order was
placed in, whichever channel resolved it — no store emulation needed, unlike the invoicing consumer.
Whether it leaves within the request or on the next `sales_email` cron run stays
`sales_email/general/async_sending`, untouched. A failure to send is logged and never fails the
resolution: the gift card is debited either way.

An Ogloba order that never gets a verdict and is invoiced by hand in the back office gets no
confirmation, as any pending payment order would — the admin order view resends it.

## Notes

- Hyvä checkout compatibility is not covered by this module (Luma checkout only);
