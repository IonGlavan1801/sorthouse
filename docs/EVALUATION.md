# Evaluation

All numbers below are measured by `php artisan eval:run` over the real pipeline (same code path as the demo) and stored in the database; the raw output is committed as `docs/calibration.json` (dev) and `docs/evaluation-holdout.json` (held-out). Nothing here is typed in by hand except this commentary.

## Corpus
Synthetic, deterministic (seeded generator `php artisan fixtures:build`): 163 emails.

| Split | Emails | Notes |
|---|---|---|
| dev | 54 | used to write rules and choose the threshold |
| holdout | 100 | run after the rules and `policy.v1` were frozen (commit `f5402ba`); metric-aggregation code was fixed afterwards and both runs repeated, with no change to rules or thresholds |
| adversarial | 12 | prompt injection in email, quote, signature, hidden HTML, hidden PDF text, image text |
| failure | 10 | malware, MIME mismatch, EICAR, PDF JavaScript, oversized, too many attachments, corrupt PDF, empty, unicode noise, huge body |
| showcase | 9 | guided demo only, not evaluated |

Across dev+holdout: 38 invoices (PDF, photographed JPEG, image-only PDF; 3 label layouts, 6 currencies, 6 date styles, deliberate mismatches, ambiguous dates, unknown vendors, missing PO), 22 complaints, 22 RFQs, 19 general questions, 17 spam/phishing, 9 order requests and 27 ambiguous or low-confidence cases. About a quarter of dev and a third of holdout messages in each family are paraphrases without the keywords the baseline knows ("hard" cases, expected route review).

Ground truth per case: category, expected normalized fields, fields that must stay null, expected route (`AUTO_ELIGIBLE`/`REVIEW_REQUIRED`/`BLOCKED`), expected action, reason codes.

## Engine under test
`heuristic-baseline-v1`: regex and keyword rules, **not an LLM**; OCR is simulated with deliberate misreads. Real-model runs have not been performed here (no credentials were supplied); when keys are added a run stores its own provider label and must be reported separately.

## Results (policy.v1, auto_min 0.85)

| | Dev (76 = 54 + 22 safety) | Held-out (122 = 100 + 22 safety) |
|---|---|---|
| Macro F1 / accuracy | 89.6% / 88.9% | 80.0% / 81.0% |
| Ambiguous-case accuracy | 57.1% (n=14) | 48.7% (n=37) |
| Invoice F1 | 100% | 100% |
| Critical-field accuracy (invoice number, total, currency, due date, PO, plus complaint/RFQ criticals) | 95.1% (82 cells) | 93.1% (144 cells) |
| Documents with all critical fields right | 84.6% (26) | 82.2% (45) |
| Invoices exact, PDF / photo / scan | 100% / 25% / 0% | 100% / 0% / 0% |
| Evidence coverage of non-null critical fields | 100% | 100% |
| Automation coverage | 15.8% (12 auto) | 16.4% (20 auto) |
| Precision of auto-processed | 100% | 100% |
| **False auto actions** | **0** | **0** |
| Review rate / blocked rate | 63.2% / 21.1% | 70.5% / 13.1% |
| Unsafe cases blocked | 16/16 | 16/16 |
| Route agreement with ground truth | 93.4% | 95.9% |
| Pipeline latency p50 / p95 | 104 / 207 ms | 98 / 175 ms |
| Estimated cost per case | $0 (baseline) | $0 (baseline) |

How to read it: the baseline is weak on paraphrases (held-out macro F1 80%), but its failures are conservative: an unrecognised message becomes `other_or_uncertain` and goes to a person instead of being automated, so false auto actions stay at zero. Photographed and scanned invoices are read with deliberate misreads, so their documents are exact only after human correction; the LOW_OCR_CONFIDENCE rule routes them to review. Evidence validity is 100% by construction (evidence that does not resolve is never stored); coverage is the measured part. Latency is wall time of the in-process pipeline with a synchronous queue and no network model call, not a user-visible latency.

## Threshold selection
`docs/calibration.json` holds the sweep. On dev there are zero false auto actions at every threshold from 0.50 up, so dev cannot discriminate between thresholds. `auto_min = 0.85` is therefore a conservative choice, not a calibrated optimum: it keeps clearly signalled spam, general questions and RFQs automatable (coverage 15.8% on dev vs 22.4% at 0.50) and leaves weak-signal cases to people. The held-out sweep (also zero false autos from 0.50 up) does not argue for changing it, and it was not used to choose it. Signals are not calibrated probabilities; a calibration against a real model would need its own sweep.

`php artisan policy:simulate policy.v2-candidate holdout` replays a run under a lower threshold (0.75) and reports coverage and false auto actions before activation. On this corpus it raises coverage to about 20% with the same zero false autos, which is weak evidence because the corpus is small and synthetic.

## Known weaknesses
Ambiguous-case accuracy below 50% held-out; spam/phishing paraphrases are missed (routed to review, not automated); the corpus was written by the same author as the rules, so real inboxes will be harder; 122 cases cannot support a claim about production.
