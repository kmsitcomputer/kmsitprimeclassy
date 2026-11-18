**# RECON CLOSURE — ARCHITECTURE READINESS**



Closure inspection: 2026-11-11T21:40:57+07:00 (Asia/Jakarta). Repository \`/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy\`; branch \`fix/transaction-reset-financial-dependencies\`; HEAD \`6a14041c06e6258c9f011fcb11271c7d34d65d17\`. Branch/HEAD unchanged from prior RECON.



**\*\*RECON CLOSURE ONLY. NO IMPLEMENTATION.\*\*** New Owner directive supersedes conflicting assumptions in RECON-01…07. All schema/API/action names in proposals are **\*\*PROPOSED\*\***, not implemented or architecture-approved. Source evidence is not runtime PASS.



[01 OWNER-DECISIONS]\(RECON-CLOSURE-OWNER-DECISIONS.md) · [02 HISTORICAL-POS]\(RECON-CLOSURE-HISTORICAL-POS.md) · [03 IMPORT-BESTSELLER]\(RECON-CLOSURE-IMPORT-BESTSELLER.md) · [04 TRACEABILITY-MATRIX]\(RECON-CLOSURE-TRACEABILITY-MATRIX.md) · [05 ARCHITECTURE-READINESS]\(RECON-CLOSURE-ARCHITECTURE-READINESS.md) · [06 OWNER-REVIEW]\(RECON-CLOSURE-OWNER-REVIEW\.md)



**## Executive verdict**



**\*\*PARTIAL — OWNER DECISIONS REQUIRED.\*\*** The closure architecture is reviewable but cannot be declared Architecture Locked or implementation-ready. All11 REM, five new Owner locks and12 historical scenarios are mapped. Historical POS is not existing checkout. Evidence confirms meaningful reuse and concrete missing integration guarantees; no new POS table is proposed, but complete no-new-table feasibility depends on D-01…07 admission/evidence/partial settlement requirements.



**## Proposed architecture and sequence**



Existing domains remain canonical. Propose an explicit historical admission coordinator within Order domain, with typed evidence/reconciliation fields on existing tables and bounded historical request/policy. No parallel order/payment/stock/fee ledger, no fake user/CRM/carrier, no untyped payload state. Reuse calculations, snapshots, authorized writers and canonical locks rather than blindly call createOrder and replay events.



1\. Authenticate allowed historical recorder (super_admin/admin/agen) and resolve valid active not-deleted Agent. Admin own branch only; SuperAdmin explicit choice; Agen self/branch valid. Invalid/ambiguous branch fails closed (CL-E27/28, CL-DB01).

2\. Validate requester account/manual as origin distinct consumer/referral/recipient/actor; enforce selected chain ownership, consumer optional/referral-scoped. Keep placed_by_user_id authenticated write-once actor.

3\. Validate manual business date ≤today WIB with no business lookback limit; actual receipt/payment/shipping/fee settlement dates carry separate precision/source, recorded/verified/audit remain now. No invented actual timestamps.

4\. Exact external-source lookup plus authorized similarity candidate review, stable Agent-scoped request key/fingerprint. Same effective request replays same order; altered key content conflicts; similar data is not automatic identity.

5\. Compute catalogue price/discount/fee server; compare historical evidence. Discrepancy cannot be overwritten manually, reconstructed from old date or silently treated as correct current price (D-05).

6\. Reconcile stock by item: completed and already reflected→no new reserve/deduction; not reflected→separately evidenced approved count adjustment; outstanding→current commitments only if expressly admitted with current capacity (D-01/07). No fallback source.

7\. Reconcile old customer receipts through Finance/SuperAdmin verification under existing separation of duties. Actual old receipt date separate verification now; no second collection or cash-in event just for registration (D-02).

8\. Reconcile per-item/type/beneficiary entitlements and off-system payout evidence. No new payable on already settled fee; no guessed historical fee basis. Approved status not paid (D-03/05).

9\. One canonical order admission with typed reconciliation state; preserve existing lifecycle transitions/lineage/grouping. Either block save until evidence ready or allow one explicitly quarantined primary order excluded actionable queues until approved, pending Owner review—never a draft order copied into another final order.

10\. Reports/List Order/Invoice/CMS adapt to new origin and dates explicitly. Historical facts do not automatically run fresh warehouse pickup/courier assignment/commission payout/old-notification replay. Future remainder fulfilment continues only through existing authorized workflow.



This sequence is design prose, not code or a coding prompt. No new endpoint is labeled existing. Creating a historic factual order is not permission to synthesize payment verification, actual delivery or financial settlement.



**## Additive schema proposals and constraints**



All proposed names remain PROPOSED; detailed field matrix in HISTORICAL-POS. The original two proposal families (requester+business date) are insufficient for the newly explicit H scenarios unless event/reconciliation/evidence gaps are resolved.



\| Family | Existing table(s) | Proposed adjustment | Integrity/control |

\|---|---|---|---|

\| S-H1 identity/date/origin | orders | Nullable recording kind, independent requester account/name/phone snapshots, optional consumer relation, historical business DATE, typed reconciliation status/verified actor-time | Legacy origin/created_at unchanged; no repurpose notes/placed_by/CRM; requester snapshots private and immutable after admission |

\| S-H2 request/source identity | orders | Nullable Agent-scoped unique historical request key/fingerprint, source-system/external-reference when trusted | Historical writer requires key; changed fingerprint409; optional NULL reference not sole duplicate protection; cross-channel exact match verified not auto merge |

\| S-H3 actual fulfilment and inventory reconciliation | order_items, optionally shipments | Typed actual receipt time/verification/proof, reflected/not-reflected state, link existing approved opname; reuse split conservation instead of redundant qty when possible | No new order status; no using fulfilled_quantity as arrived; no fake current handover; no side effects before reconciliation |

\| S-H4 actual old receipts/evidence | payment_transactions / verification tables | Actual received time where existing cash fields insufficient, typed historic reference/key and evidence relation | Paid balance writer remains PaymentService; verified_at now; existing proof privacy and separation duties preserved |

\| S-H5 external commission settlement | commissions / existing commission adjustments only where semantically correct | Proven external payout ref/time/evidence/verified actor; targeted duplicate constraints after audit | No settlement writer currently; paid state field not authorization; no fake negative fee to mask payment; partial settlement model may remain blocked pending D-03 |

\| S-I1 row import correlation | stock_opnames / stock_opname_items | Source/row key, fingerprint, durable row/approval linkage, freshness version if required | No new import/POS shadow ledger; creator/Gudang/Admin actual identities; approved same-document replay≠new document replay |



Proposed unique index candidates need full historical duplicate/null audit before constraints; do not impose item+type uniqueness that incorrectly bans multiple valid beneficiaries/legacy rows without evidence. Local commissions has only PRIMARY unique (CL-DB03), despite old docs implying courier uniqueness—current service uses existence guard and Order locking; do not claim a schema guarantee absent in runtime metadata. Strict multi-connection tests are future plans, not performed.



Business historical date should use DATE; actual old event instants may require DATETIME rather than relying on existing timestamp range. Preserve unknown precision/timezone instead of inventing midnight. Database physical range is a technical compatibility check, not a discretionary business lookback restriction. All additions nullable for old rows, normal checkout writes remain as before; no mass backfill, no index executed, no migration file generated.



**## Cross-module compatibility assessment**



\| Domain | Existing contract | Proposed historical integration / risk |

\|---|---|---|

\| Orders | Account/CRM-origin service, state machine, per-Agent ownership, item snapshots | New typed historical requester origin resolver/readers; one primary order; current create method not shortcut (CL-E01/29) |

\| Inventory | Current reserve, bucket ledger, Sub parent→stock→reserve, Order→StockRequest→inventory, canonical target order | Completed historic no automatic reserve; approved reconciliation via existing opname when required; no second lock ordering vocabulary (CL-E03/04; prior EV09) |

\| Payments | Canonical total/paid/remaining; verification role separation, current paid_at stamping | Old actual date separate verification, no implicit cash receipt; admission rules cannot be bypassed silently (CL-E14…17/33) |

\| Referrals | Stable Agent/selected beneficiary, selfpurchase fallback regular | Historical selected referral independent requester; same-Agent/current hierarchy validated; no auto reconstruction old chain or ownership remap (CL-E02/29) |

\| Commissions | Per-item/type accrual/finalization; payout outside app, no writer | External-paid evidence required; settled amount does not become current payable; partial-paid mapping D-03 (CL-E18…20) |

\| Fulfilment | Per-item status/active billable qty, split conservation | Completed/outstanding partition; actual receipt separate planned date; no fictional current send/deliver events (CL-E10…12) |

\| Shipping | Canonical shipment grouping and authorized assignment | Historical executor requires factual proof; no fake live courier assignment; future remainder uses coordinator authority |

\| Invoice | Number order_no, current issuance eligibility/snapshot, scoped financial access | Do not overwrite issue date/number to masquerade old original invoice; historical external reference separate; visual REM10 stays presentation (CL-E30) |

\| Reports | Created_at/order_created_at windows and live canonical balance | Explicit business/recorded/receipt/verified/delivery/settlement dimensions; closed-period policy D-04; no silent rewrite locked initial snapshot (CL-E24…26) |

\| RBAC | Single Agent FK plus role and scopes; null guard | Actively validate referenced Agent not deleted/inactive; requester/consumer visibility explicit, no placed_by grants (CL-E27/28; CL-DB01) |

\| Audit | Actual actor/time and append logs | Historical event time stored separately, change reasons/evidence/review preserved; no audit timestamp backdate |

\| Notifications | Normal lifecycle afterCommit events | Do not resend historical operational messages; allow only explicitly agreed recording/review notifications, existing pipeline once (CL-E32) |

\| CMS | Existing blocks manual/category | Add Agent net verified-delivery ranking only admitted historical facts; event/return stage D-06 (CL-E31) |

\| Product import | Global SKU and inventory approval separate roles | Target count per row, typed durable retry key, existing reserve safeguards and conflict409, D-07 staging contract (CL-E03…09/34) |

\| Product/media/categories | Existing parent/variant/gallery/tree relations | REM02…08 proposals retained; no unrelated redesign or historical deletion; actual missing image/mockup evidence unchanged |



**## Risk register — prospective architecture risk, not reproduced incident**



\| Risk ID | Concern / evidence | Severity | Mitigation and acceptance before lock/implementation |

\|---|---|---|---|

\| R-H01 | Stock double reservation/deduction if ordinary create used; CL-E01/02 | P1 | D-01 reflected classification, zero stock effects for already reflected units, approved linked reconciliation once |

\| R-H02 | Old receipt double credit/fresh cash recognition; CL-E14…17 | P1 | D-02 evidence, canonical payment writer, stable receipt key, actual vs verified dates, self verify denied |

\| R-H03 | Already-paid off-system fee becomes payable; CL-E18…20 | P1 | D-03 settlement evidence/authority, one entitlement and no artificial reversal |

\| R-H04 | Closed-period history restated or currentperiod inflated; CL-E24…26/30 | P1 | D-04 explicit dimensions/report contract, historical business date never created_at; no current cash-in by registration alone |

\| R-H05 | Duplicate order/receipt/fee on changed key/source identity; CL-E01; CL-DB03 | P1 | Durable unique Agent request key+fingerprint, exact external identity, similarity reviewed not auto-merged |

\| R-H06 | Wrong Agent/unauthorized verification or PII view; CL-E27/28/33; CL-DB01 | P1 | Fail closed invalid branch case, explicitSA Agent, separate verifier, role matrix, requester privacy |

\| R-I07 | Target import conflicts with commitment/approval or reruns row; CL-E03…09/34 | P1 | D-07 actor+staging rules, stock conflict409/recount, row-level durable idempotency and reservation conservation |

\| R-B08 | Bestseller early item/late cash/refund fanout wrong; CL-E10…15/21…26 | P2 | D-06 event grain, net per item preaggregation, valid Agent scope, late-payment/cross period test expectations |

\| R-V09 | Missing approved invoice/POS visual reference | P2 | Obtain approved artifacts; preserve locks and official logo, do not manufacture parity |



Counts:9 prospective risk entries, P0=0/P1=7/P2=2/P3=0. The13 baseline gap findings are not merged with this risk count. No runtime financial loss/security exploit asserted.



**## Acceptance and scoped test strategy — plan only**



Every H01…H12 must have explicit positive/negative/retry expectations after Owner answers. Minimum invariants: one source/Agent/order; no historical completed reserve if reflected; approved stock delta once if not; outstanding current commitment only real remainder; paid summary equals canonical verified accepted receipts; no unverified auto LUNAS; fee settled externally never new payable; unit conservation for splits/returns; all dates separate; no backdate cap except no future; no guessed prices/fees; new identity no fake account/CRM; legacy orders no mutation; exact duplicate replay one order and differing fingerprint conflict; cross-Agent actors denied; current invalid AdminAgent case fail closed; closed period/reporting explicit; CMSverified fully paid actual delivery Agent net unit once.



Plans include real DB two-connection reserve/opname/admission/key races; focused PaymentCore/Invoice/AssistedOrder/StockOpname/GlobalSku/fulfilment/commission/report tests according actual changed modules; frontend required identity/date/source/reconciliation/duplicate/visual cases. Existing test names/method evidence baseline RECON06; no test/build/migration run in closure. Full suite remains prohibited. Implementation validation requires separate authorization and Owner stage gate.



**## Dependency order and remaining blockers**



Owner reviews D-01…07 → approve typed data/event/authority/row atomicity contracts → provide approved invoice/POS mockups and representative image evidence → review one historical admission design and cross-domain failure/rollback matrix → Architecture Lock by Owner only. Future implementation, if separately authorized: safe catalogue read/gallery/filter foundation, category/variant changes and guarded delete, SKU import with existing approval adapter, SKS source-aware UI, ranking after event lock, invoice presentation, historical admission integration last after reconciliation/period policies. This is sequencing analysis, not an implementation prompt or authorization.



Rollback/recovery design must use idempotent existing canonical writers and evidence-linked reconciliation; not create-then-compensate entire checkout, not erase historical paid/fee/movements, not silently rollback real cash by status toggle. Expand nullable first, audit references/nulls/duplicates, preserve existing snapshots, no historical remap. Production is entirely outside this closure.



Final lock readiness: proposal is ready to discuss, but **\*\*Owner Review and remaining decisions/evidence are required before Architecture Lock\*\***. No finding is automatically remediated.



**## Frontend parity architecture addendum**



Latest Owner lock requires shared CO/POS transaction presentation per grain without redesign. HISTORICAL-POS CL-P01…10 maps current item review lists and canonical ordercards/control contracts; TRACEABILITY PARITY-01 tracks one added partialaudit unit. Share display/format/status/applicableactions and GET/orders canonicalidentity; maintain distinct regular checkout and historical admission handlers. Shared historical date slot must preserve created_at and appear identically for historical rows in either context. No table component/code or literal new CO grid invented. Final exact reference/design evidence remains partial.
