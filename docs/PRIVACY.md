# Privacy

- **Data**: all messages, invoices and images are synthetic (`fixtures/`). No real customer data or PII is used or accepted by the public demo (there is no upload endpoint).
- **Session data** (messages, extracted fields, evidence, decisions, audit, usage, stored files) is tied to one demo session with a TTL (`DEMO_SESSION_TTL_MINUTES`, default 120). `demo:purge` runs every five minutes and hard-deletes expired workspaces and their files.
- **Delete now**: the button disables the session immediately and queues an asynchronous purge. Those are two different moments and the UI says so.
- **Logs**: application logs do not contain message bodies, attachments or extracted values. The public trace is redacted (hashes and prefixes of hashes, no raw provider payloads) and limited to the session.
- **Providers**: by default nothing leaves the server. If an operator enables OpenAI, Anthropic or Gemini, the sanitised subject, body text and extracted attachment text (and images for vision) of synthetic messages are sent to that provider under the retention and training terms of the operator's account. Those terms change; verify them at the time of configuration. The IP of a visitor is stored only as a salted hash for rate limiting.
- **Feedback**: corrections are stored in `field_corrections` with `dataset = production_demo`; they are never mixed into the evaluation holdout and are not used for fine-tuning.
- **Audit export** contains no secrets or other workspaces' data.
