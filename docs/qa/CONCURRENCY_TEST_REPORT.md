# Round 2 — PostgreSQL concurrency

التنفيذ في [check-round2-sales-races.php](../../.tools/check-round2-sales-races.php) و[worker](../../.tools/round2-sales-race-worker.php)، قاعدة `erp_elec_new_test` فقط. عمليتا PHP مستقلتان تنتظران readiness barrier ثم تستعملان Actions الحقيقية عبر اتصالين منفصلين. `sales-races.json` يسجل الفائز والرفض والقيم الملتزمة، وليس مجرد وجود `lockForUpdate` في المصدر.

| ID | المنافسة | شرط النجاح |
| --- | --- | --- |
| R2-RACE-01 | quotation same version، قيمتا project مختلفتان | فائز واحد + ValidationException stale؛ version يرتفع مرة وDB تطابق الفائز |
| R2-RACE-02 | sales order same version | نفس الضمان |
| R2-RACE-03 | إنشاء sales order بنفس creation_key | كلا الطلبين يعيدان نفس id؛ draft واحد وبند واحد وversion=1 |
| R2-RACE-04 | إلغاء sales order بنفس version | انتقال واحد؛ المنافس stale؛ status=cancelled/version+1 |

دليل التنفيذ: [sales-races.json](round2/evidence/sales-races.json). حفظ تاريخ التسليم blank كـSQL NULL مشمول. السجلات الصناعية تنظف بعد التنفيذ؛ لا تستخدم قاعدة العمل. يتم تعطيل مستمعي أحداث Sales لهذه المجموعة المحدودة لتجنب سجلات audit دائمة لfixtures محذوفة؛ التحقق من transaction/audit الحقيقي موجود في الاختبارات المتسلسلة، لذلك هذا ليس اختبارًا لتزامن audit bridge.

NOT RUN: تنافس موافقتين quotation، إنشاء quotation متزامن بإعادة HTTP snapshot نفسها، تغيير role/scope لحظة commit، سباقات فروع/primary contacts، load/stress، deadlock retry، official numbering، conversion allocation، inventory reservation/issue/release، invoice/delivery idempotency. القدرات التي لا تملك producer أصلًا تبقى BLOCKED؛ لا تُحاكى PostgreSQL عبر SQLite ولا يُصطنع نجاح مالي.
