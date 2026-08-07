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
- `Service/` — one class per action, each exposing a single `execute()`. `SettleOrder` and
  `ProcessReturn` orchestrate and are what the controllers call; `ReadPaymentReturnPayload`,
  `IsOrderPaid`, `IsOrderAwaitingPayment`, `MarkOrderAsProcessing`, `CancelOrder`, `OrderLockAcquire`,
  `OrderLockRelease`, `SendOrderEmail` and `BuildHistoryComment` do one thing each. A collaborator
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
read through one value object, and `Service/SettleOrder` applies the verdict whichever channel brought
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

`Refunded` currently falls through to cancellation, but only ever reaches a `processing` order, where
the settlement is ignored and logged instead. Issuing a credit memo is not implemented.

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
unrecognised. These comments are admin side only and, like the rest of the module's back office
wording, are translatable but not shipped in the `i18n` CSVs — only customer facing strings are.

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
| Return unproven, claiming anything else, or empty | **untouched** | cart + quote restored |

Every return, proven or not, notes in the order history that the customer came back from the payment
page. That is the one write the return makes, and it settles nothing.

**Beyond it, an unproven return never touches the order.** Not the state, not the status, not the
payment information. It only decides where to send the customer, and restores the quote in the session
when it sends them back to the cart. The notification settles the order, as it always does, whenever
it arrives.

That is a deliberate trade: the customer follows the path they expect, and the merchant keeps an order
that no unverified request has altered. The cost is that a customer whose payment did succeed can be
sent back to a restored cart.

The message they see says only what is known. When the transaction is settled — proven by the return
itself, or already settled by the notification — it is stated plainly:

> The payment was not completed and your gift card has not been charged.

When nothing could be proven and the order is still pending, it promises nothing:

> Your payment could not be confirmed. Should your gift card have been charged, your order will be
> validated automatically.

The order state is still read, never written, before that decision: when the notification has already
settled the order — which the observed traffic says is the common case, it lands a few hundred
milliseconds ahead of the browser — the redirect follows the settled order rather than an unverifiable
claim. Without that read, an unproven return claiming failure would restore the cart of an order that
was in fact paid, and invite a second payment.

A `Charged` response landing on an order that was already cancelled is written in the order history
and logged as an error for manual review.

## Order view

`Block\Adminhtml\Info` fills the Payment Information box of the admin order view with the Ogloba
references of the transaction: payment status, the environment the order was sent to, Ogloba order id
and operation id, order sequence, the two Limonetik ids the module generated, and which channel
settled it. Empty values are dropped, so a transaction that never got a verdict shows only what it has.

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
