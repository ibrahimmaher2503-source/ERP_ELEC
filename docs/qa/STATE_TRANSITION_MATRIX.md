# Round 2 — مصفوفة الانتقالات

المصدر: Actions الفعلية، [عقد Q-01–29](../m04-quotations-contract.md)، [SO-01–11](../m04-sales-orders.md)، [الكتالوج](DEEP_SCENARIO_CATALOG.md). «Guard PASS» يعني رفضًا صحيحًا، وليس تنفيذ العملية المحجوبة.

| الكيان / الحالة | الفعل والحالة التالية | الممنوع / أثر قاعدة البيانات | الاختبار / حدود النتيجة |
| --- | --- | --- | --- |
| Company/Branch/User | create ثم versions متتابعة | stale version لا يغير القيم | M وchecks M01؛ lifecycle متكرر |
| Branch active | deactivate ثم reactivate | منع تعطيل فرع مرتبط بمستخدم نشط | check-m01.php؛ database persistence |
| User active | disable، revoke sessions، password reset | حساب معطل/جلسة قديمة لا تنفذ business action؛ لا secrets في audit | check-m01-users/rbac |
| Role matrix | grant ثم revoke | last-admin، self escalation، protected Administrator matrix | role-permission-propagation وM01 RBAC |
| Product active/inactive | status toggle مع version | تغيير التعريفات الثابتة أو dimension مرفوض | check-m02 وM؛ لا stock effect |
| Customer/Supplier | active/inactive/archived | status يحتاج صلاحية مستقلة؛ العلاقات محفوظة | M03 baseline؛ دورة متصفح status ليست مكتملة |
| Quotation draft | save → draft/version+1 | صفر بنود، invalid quantity/discount، stale لا يكتب | Q، regression reopen/repeat |
| Quotation draft | submit → pending_approval | blockers أو سبب استثناء ناقص يمنعان التقديم | Q؛ مصدر سعر محقون في الاختبار فقط |
| Quotation pending_approval | withdraw → draft | تعديل مباشر/موافقة قديمة مرفوضان | Q؛ content/version snapshots |
| Quotation pending_approval | approve/reject → draft مع قرار محفوظ | self-approval بلا إعداد، صلاحية مفقودة، policy/hash/version مختلف | Q؛ approval ليس issue |
| Quotation draft/pending | cancel → cancelled | pending request يسحب؛ المسودة المغلقة غير قابلة للتعديل | Q؛ append-only decision |
| Quotation cancelled/rejected/expired | edit/reopen/issue غير متاحة | لا إعادة فتح حالة مغلقة عبر save | Q/readiness؛ issued states غير موجودة في schema الحالي |
| Quotation issued/revised/accepted | issue/revise/accept/convert | BLOCKED؛ لا رقم ولا PDF ولا acceptance/conversion مثبتة | Q guard؛ دورة نجاح NOT IMPLEMENTED |
| SalesOrder draft | save → draft/version+1 | stale، forged source/status/totals، out-of-scope | SO وC |
| SalesOrder draft | submit → pending_approval | revalidate branch/customer/products؛ لا reservation | SO |
| SalesOrder pending | withdraw/reject → draft | self rejection غير مسموح؛ snapshots immutable | SO |
| SalesOrder draft/pending | cancel → cancelled | لا كميات أو تاريخ قرار يحذف؛ replay قديم مرفوض | SO وrace cancel |
| SalesOrder approved/executed | amend/reserve/deliver/invoice/return/close | BLOCKED على العقود الناقصة؛ لا postings مصطنعة | SO guard PASS فقط |

التنافس الحقيقي منفذ لتحديث quotation/order وإلغاء order وإعادة إنشاء order بنفس المفتاح. قرارات quotation المتزامنة، تغييرات الصلاحيات المتزامنة مع commit، ومنافسة المخزون/الفواتير لم تُثبت باختبار متعدد العمليات. اقرأ [تقرير التزامن](CONCURRENCY_TEST_REPORT.md).
