# Round 2 — كتالوج السيناريوهات المتتابعة

هذا امتداد [لكتالوج الجولة الأولى](BUSINESS_SCENARIOS.md)، وليس بديلًا للعقود أو دليلًا على اكتمال الوحدات. نتائج الجولة الثانية النهائية في [التقرير](QA_ROUND_2_FINAL_REPORT.md). لا يُحسب عدد assertions على أنه عدد رحلات مستخدم. فشل تجهيز المتصفح أو محدد عنصر في Playwright ليس عيبًا مثبتًا في التطبيق.

## بيانات وشروط التنفيذ

كل السيناريوهات تستخدم PostgreSQL `erp_elec_new_test` فقط. الفاعلون حسابات اختبار نشطة ومؤكدة بصلاحيات صريحة؛ لا تعني المصادقة سلطة عامة. بيانات `R2-*` و`DEMO-*-CHECK` صناعية. اختبارات Actions/Livewire تستخدم rollback وقرص ملفات خاصًا، ورحلات المتصفح تحفظ fixtures في قاعدة الاختبار لتستطيع طلبات HTTP قراءتها. لا تُعد هذه البيانات موافقات أو ترحيلات حقيقية.

اختصارات الاختبارات: **M** = `.tools/check-round2-masters.php`، **Q** = `.tools/check-m04-quotations.php`، **SO** = `.tools/check-m04-sales-orders.php`، **C** = `.tools/check-round2-sales-races.php`، **B** = `tests/E2E/round2-masters.cjs`، **BQ** = `tests/E2E/quotation-reopen-save.cjs`. المسارات من جذر المشروع. ملف نتائج كل مجموعة يذكر assertions المنفذة؛ لا تعني إحالة مجموعة قديمة إلى أكثر من ID تنفيذ كل التركيبات الممكنة.

| ID | الوحدة / العقد / السيناريو | الدور والبيانات / الخطوات | المتوقع | التنفيذ والدليل / العيب |
| --- | --- | --- | --- | --- |
| R2-ORG-01 | M01 company singleton / تعديل متكرر | company_update؛ قراءة version، حفظ ثم إعادة قراءة وتعديل ثلاث مرات | نفس الشركة، زيادة version مرة لكل حفظ | M؛ `round2/evidence/master-lifecycle.json` |
| R2-ORG-02 | M01 branch / دورة فرع | branch_create/update/admin؛ إنشاء ثم ثلاث تعديلات وإعادة قراءة | هوية واحدة وversion=4 | M؛ نفس الدليل |
| R2-ORG-03 | M01 branch / فشل ثم استعادة | نفس الفاعل؛ إعادة كود فرع موجود ثم قراءة version | رفض الحفظ وعدم تغيير السجل | M؛ نفس الدليل |
| R2-ORG-04 | M01 user / دورة حساب | user_admin/create/update/view؛ إنشاء حساب ثم ثلاث تعديلات | تحديث الهوية دون إنشاء أدوار أو صلاحيات | M؛ نفس الدليل |
| R2-ORG-05 | M01 user / بريد غير صالح | محاولة إنشاء ببريد غير صالح، ثم قراءة الحساب السابق | ValidationException وعدم تغيير السجل السابق | M؛ نفس الدليل |
| R2-ORG-06 | M01 RBAC / منح وسحب | مسؤول أمان وعضو دور مستقل؛ منح، استعمال، سحب، إعادة استعمال نفس User | السحب نافذ فورًا؛ لا تصعيد ذاتي | `check-role-permission-propagation.php` و`check-m01-rbac.php`؛ receipt المشغل الآمن |
| R2-ORG-07 | M01 Administrator / حماية السلطة | explicit Administrator وحساب آخر؛ فحص known/unknown keys والحدود | كل المفاتيح المعروفة متاحة؛ النطاقات والحساب النشط تظل لازمة | `check-administrator-defaults.php` وSO؛ receipt المشغل الآمن |
| R2-ORG-08 | M01 audit / عدم التزييف | عمليات ناجحة ومرفوضة، محاولات تعديل التاريخ | إسناد صحيح، أسرار محجوبة، تاريخ append-only | `check-m01-users.php` و`check-m01-rbac.php`؛ receipt المشغل الآمن |
| R2-PROD-01 | M02 category / دورة تصنيفات | category_manage؛ أب وابن وحفيد، محاولة حلقة، ثم ثلاث تعديلات | رفض الحلقة واستمرار التعديل الصحيح | M؛ master-lifecycle.json |
| R2-PROD-02 | M02 product / إعادة الحفظ | product_create/update؛ ثلاث تعديلات اسم ومواصفة مع تحويل scale8 | الهوية والتحويل ثابتان، آخر مواصفة محفوظة | M؛ master-lifecycle.json |
| R2-PROD-03 | M02 product / stale ثم recovery | تقديم version قديم ثم الحالي | لا lost update؛ الحفظ الصحيح اللاحق ينجح | M؛ master-lifecycle.json |
| R2-PROD-04 | M02 barcode / تعارض | إنشاء بباركود موجود ثم فحص عدد وversion | رفض دون سجل جزئي | M؛ master-lifecycle.json |
| R2-PROD-05 | M02 media / إضافة وإزالة | media_manage؛ رفع PDF خاص، إزالة، قراءة tombstone والملف | الإزالة تحفظ التاريخ؛ لا ملف عام | M و`check-m02.php` |
| R2-PROD-06 | M02 permission / سحب أثناء الاستخدام | سحب product_update من نفس الفاعل ثم محاولة حفظ | 403 والسجل دون تغيير | M؛ master-lifecycle.json |
| R2-PARTY-01 | M03 customer / دورة ملف وبيانات تابعة | customer_create/update/contact/address؛ ثلاث تعديلات، اتصال وعنوان رئيسيان | آخر القيم محفوظة، رئيسي واحد | M؛ master-lifecycle.json |
| R2-PARTY-02 | M03 supplier / دورة مستقلة | supplier_*؛ نفس الدورة بجهة مورد وعنوان registered | لا اختلاط بحقوق أو بيانات العميل | M؛ master-lifecycle.json |
| R2-PARTY-03 | M03 customer / نص NUL ثم recovery | تعديل phone وcity بمحرف NUL، فحص fresh والسجل الرقابي، إعادة إدخال صحيح | خطأ حقل، لا تغيير ولا audit نجاح | M؛ قبل/بعد `master-lifecycle-before-fix.json` و`master-lifecycle.json`؛ R2-PARTY-001 |
| R2-PARTY-04 | M03 supplier / نص NUL ثم recovery | نفس المحاولات على مورد مستقل | نفس الضمانات | M؛ نفس الأدلة؛ R2-PARTY-001 |
| R2-PARTY-05 | M03 duplicate/stale / فشل قابل للاستعادة | كود عميل مكرر، version قديم، نوع مورد غير مسموح، حفظ صحيح لاحق | رفض وعدم إنشاء سجل/audit نجاح | M؛ master-lifecycle.json |
| R2-PARTY-06 | M03 shared primary / إعادة تحرير الرئيسي | تحرير جهة الاتصال/العنوان الرئيسي في العميل والمورد | الحفاظ على سجل واحد وعدم اصطدام unique index | `check-party-primary-edit.php` وM؛ receipt المشغل الآمن |
| R2-SYS-01 | Shared import / template وpreview | تنزيل قالب، version خاطئ، صيغة Excel، صف صالح، تأكيد صريح | preview بلا كتابة؛ التأكيد عبر owner Action فقط | `check-data-operations.php` وM03 checks؛ receipt المشغل الآمن |
| R2-SYS-02 | Shared bulk/export / stale وscope | اختيار سجلات، preview، تغيير version، تنفيذ وتصدير | نتائج صفوف صادقة، لا تعديل صامت، scope وformula-safe | `check-data-operations.php`؛ receipt المشغل الآمن |
| R2-Q-01 | Q-26 / العطل المبلغ عنه | Sales مع view/create/edit وM02/M03 lookup؛ فتح مسودة expiry=NULL، تعديل ثم Save | نجاح وحفظ في نفس المسودة | Q وBQ؛ `quotation-reopen/beforefix` ثم الأدلة النهائية؛ R2-Q-001 |
| R2-Q-02 | Q-26 / نفس instance مرتين | إعادة فتح component، حفظ، تعديل، حفظ ثانٍ | تحديث recordId/version وعدم stale ذاتي | Q؛ R2-Q-002 |
| R2-Q-03 | Q-26 / إنشاء ثم Save مكرر | component جديد، حفظ، تعديل، حفظ ثانية | صف واحد ونسخة محدثة | Q؛ R2-Q-002 |
| R2-Q-04 | Q-26 / تحذير التعديلات | تعديل ثم Save؛ مغادرة تعديل غير محفوظ؛ فشل تحقق ثم مغادرة | لا تحذير بعد نجاح الحفظ، والتحذير باقٍ للتعديلات غير المحفوظة | BQ؛ `quotation-reopen/afterfix` ودليل إعادة الفحص؛ R2-UI-001 |
| R2-Q-05 | Q-11/12/14 / حسابات وحدود | كميات وأسعار scale8؛ خصم بند وعرض percent/fixed؛ سالب وتجاوز | BCMath بلا floats؛ رفض التجاوز؛ total الرسمي غير مختلق | Q؛ receipt المشغل الآمن |
| R2-Q-06 | Q-02/20–23 / طلب وقرار وسحب | سياسة وتسعير اختبار محقونان فقط؛ submit، withdraw، edit، resubmit، decide | snapshot/version/policy وصلاحيات حالية؛ الموافقة القديمة لا يعاد استعمالها | Q؛ ليس اختبار تسعير أو إصدار فعلي |
| R2-Q-07 | Q-28 / Excel إلى draft | قالب، SKU/unit غير صالح، preview ثم apply وحفظ صريح | لا حفظ تلقائي ولا استيراد سعر/خصم | Q؛ receipt المشغل الآمن |
| R2-Q-08 | Q-27/29 / ملفات وتصدير | رفع مستند خاص، صلاحية تنزيل، XLSX مصرح | ملف داخلي لا PDF رسمي؛ headers آمنة؛ تصدير صادق | Q؛ receipt المشغل الآمن |
| R2-Q-09 | Q-05/06/07/15/24 / العمليات التابعة | محاولة issue/revise/accept/convert مع غياب المنتج المالك | منع واضح دون رقم أو PDF أو كمية محولة مختلقة | Q وSO؛ guard قابل للاختبار؛ النجاح التشغيلي BLOCKED |
| R2-SO-01 | SO-01 / تاريخ التسليم الاختياري | حفظ blank requested_delivery_date ثم إعادة الفتح والحفظ | SQL NULL، لا 500 | SO وC؛ R2-SO-001 |
| R2-SO-02 | SO-01/02 / draft وإعادة الحساب | direct draft، تعديل، stale، forged totals/status/source | snapshots وdecimal strings؛ منع تعديل حالة معتمدة | SO؛ receipt المشغل الآمن |
| R2-SO-03 | SO scopes / سحب وعزل | فاعلان بنطاقين؛ direct route/export/action ثم سحب | لا تسرب ولا mutation بعد السحب | SO؛ receipt المشغل الآمن |
| R2-SO-04 | SO review/cancel / متتابع | submit، withdraw/reject، retry، cancel draft/pending | تاريخ محفوظ، قرار منسوب، لا reservation مدعى | SO؛ receipt المشغل الآمن |
| R2-SO-05 | SO-03–11 / downstream | approve/amend/deliver/invoice/return/close | BLOCKED دون مؤثر مخزون/فاتورة مصطنع | SO؛ نجاح guard لا يعني تنفيذ الوظيفة التابعة |
| R2-RACE-01 | Q row/version / تحديثان | عمليتا PHP باتصالين، barrier، نفس version وقيمتان | فائز واحد وstale واحد؛ DB تطابق الفائز | C؛ `round2/evidence/sales-races.json` |
| R2-RACE-02 | SO row/version / تحديثان | نفس تجربة التنافس على أمر بيع | نفس الضمانات | C؛ نفس الدليل |
| R2-RACE-03 | SO creation_key / إعادة طلب | عمليتان بنفس UUID | كلاهما يعيد نفس الأمر؛ صف وبند واحد | C؛ نفس الدليل |
| R2-RACE-04 | SO cancel / انتقالان | عمليتان بنفس النسخة تحاولان الإلغاء | انتقال واحد ورفض الطلب القديم | C؛ نفس الدليل |

هذه **٤٠ مجموعة سيناريو** موثقة؛ التفاصيل والقيم الفعلية داخل الاختبار والدليل المشار إليهما. الحالات التي لم تنفذ في المتصفح لا ترتقي إلى VERIFIED — PASS لمجرد نجاح Action. حالة كل مجموعة النهائية وحدودها موضحة في التقرير ومصفوفتي الحقول والانتقالات.

## المخزون المكتشف للواجهات والأفعال

التعداد الأصلي للطرق والملفات في [IMPLEMENTATION_INVENTORY.md](IMPLEMENTATION_INVENTORY.md). أعيدت مراجعته مع `php artisan route:list --json --except-vendor` وpublic methods في `app/Modules/*/Presentation` في الجولة الثانية.

| الشاشات الفعلية | عناصر التحكم / Actions المعلنة | نطاق تغطية هذه الجولة |
| --- | --- | --- |
| Company، Branch list/details، User list/details | edit/save/cancel، status، password/session، tabs | repeated Actions؛ الرحلات المرئية موثقة في E2E |
| Roles/permissions، RoleDetails، UserAccessSection، QuotationSelfApprovalSection | select/toggle/save matrix، assign/revoke role، grant/revoke branch، revokeDirect، self-approval | regression Actions/Livewire؛ ليس كل control مفحوصًا في المتصفح |
| Product catalog/form/details، categories/brands/units | filter/sort/select، save/status، conversions/specification، media، bulk/export | Actions والتدفقات المرئية الأساسية؛ بقية controls محددة في gaps |
| Customer/Supplier catalog/form/details، SupplierCategories | profile/contact/address/note/document/status، filter/sort/bulk/export | كلا الطرفين مستقلًا، مع فحص shared regressions |
| DataOperationPage | template/preview/execute/errors/result | PostgreSQL وHTTP؛ تفاصيل المتصفح غير شاملة |
| Quotations list/form/details/policy/approval/pdf/acceptance/convert | save/add/remove/move/import، terms/settings، approve/withdraw/cancel/duplicate/upload/export، readiness | فحص stateful + المتصفح للعطل؛ القدرات التابعة غائبة |
| SalesOrders list/form/details/policy، SalesReturns | save/submit/withdraw/reject/cancel/export، policy، operational guards | Actions + races؛ لا تدّعي readiness screens تسليمًا أو فاتورة |

عمليات حذف contact/address أو استبدال مستند في مكانه ليست Actions منفذة حاليًا؛ لا تُخترع أزرار أو حالات اختبار ناجحة لها. المواصفات والتحويلات والباركود أجزاء من Product aggregate وليست CRUD مستقلة عامة.
