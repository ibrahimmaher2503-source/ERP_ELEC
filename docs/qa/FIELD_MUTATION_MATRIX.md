# Round 2 — مصفوفة الحقول والتركيبات

الحدود مصدرها Validator وDraftAmounts في التطبيق؛ لا تتحول السياسة المحقونة للاختبار إلى إعداد فعلي. نتيجة مجموعة تحقق لا تثبت كل حقل وكل تركيب في المتصفح.

| Aggregate | الحقول / mutations المختبرة | الضمان | التغطية المتبقية |
| --- | --- | --- | --- |
| Company/Branch/User | اسم/اسم قانوني، code، email/gender، version، ثلاث تعديلات | قراءة مستمرة من PostgreSQL؛ أخطاء duplicate/stale/invalid email | كل حقل منفردًا في المتصفح، جلسة منتهية أثناء save |
| Product category | parent، سلسلة من ثلاث درجات ثم حلقة | منع cycle وحفظ لاحق صحيح | تنافس تعديل شجرتين في عمليتين |
| Product | الاسم/الاسم الإنجليزي، brand/category، barcode، attributes، conversion scale8، status | هوية وtrade units ثابتة، stale/duplicate rejected | pairwise شامل للعلامات/الوحدات/المرفقات في المتصفح |
| Customer/Supplier | profile name، optional values، contact name/phone، address line/city، primary | كلا النوعين مستقل، ثلاث تعديلات وinvalid/retry | كل قناة اتصال وكل طول حدّي؛ حذف تابع غير منفذ |
| Party text | phone وcity يحتويان NUL | خطأ حقل؛ fresh row وaudit دون تغيير | guard يطبق أيضًا على نصوص الاتصال/العنوان الأخرى؛ ليس ادعاء فحص مستقل لكل نص |
| Quotation | notes، version، nullable validity، quantity/requested price، percent/fixed line/global discount | decimal strings، حفظ بعد reopen/repeat، no fake tax/total | التفاصيل الإضافية والنتائج الدقيقة في دليل Q/BQ؛ لا ادعاء Cartesian شامل |
| SalesOrder | nullable delivery date، quantity/price/discount، branch/customer/source/status، creation_key | normalized NULL، server calculation، branch scope، replay | amendment/delivered/invoiced quantities محجوبة |
| XLSX | template version، SKU/unit/quantity، formulas، selected/filtered rows | preview لا يحفظ، formula-safe export | تكرار كل workbook مرتين بكل حالة NOT RUN كتركيب مستقل |

## نموذج المخاطر المستخدم

العوامل المستقلة للحفظ: new/existing، 1/multiple lines، price missing/requested، discount percent/fixed/invalid، authorized/revoked، fresh/stale version، validation clean/failed-then-retry، same component/reopened HTTP page. الحالات المنتقاة تجمع أعلى المخاطر: existing + nullable date + reopened component؛ same component + second save؛ invalid input + retry؛ stale version + conflicting processes؛ same creation key + concurrent replay؛ both Party owners + shared primary edits؛ missing prices + unknown totals.

هذا **risk-based combination selection** وليس شهادة covering array رياضي لكل أزواج العوامل. تُسجل الفجوات بدل وصف التركيبات غير المنفذة بأنها PASS. الاستخدام المتزامن لجلسات مختلفة مع تغيير كل حقل، تعطل الشبكة في منتصف upload، expiry أثناء submit، ومنافسة numbering/official PDF لا يغطيها نجاح حفظ المسودة.
