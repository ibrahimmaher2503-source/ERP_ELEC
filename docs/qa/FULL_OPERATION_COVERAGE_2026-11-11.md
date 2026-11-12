# سجل تغطية العمليات — 2026-11-11

**الهدف مفتوح والتغطية الكاملة غير مثبتة.** هذه نقطة حصر وربط، وليست شهادة قبول أو نسبة إنجاز. [المصفوفة CSV](FULL_OPERATION_COVERAGE_2026-11-11.csv) تتضمن 996 صفاً: 988 صف حصر من المصدر و8 شرائح دليل/عوائق. لا يجوز قسمة عدد الاختبارات أو assertions أو الشاشات على هذا العدد لإعلان تغطية العمليات.

## المصدر وطريقة العد

قرئت تعليمات AGENTS.md وdocs/02-prd.md وdocs/08-architecture.md و.ai/HANDOFF.md قبل الكتابة. الحصر الساكن من routes/web.php ومكونات Presentation والعقود الحالية Application/Contracts؛ تحفظ أعمدة source موضع كل دالة/طريق. دوال mount/render/boot/lifecycle مستبعدة، لكن UI_ENTRY قد يظل تحكماً محلياً كإضافة سطر أو تغيير تبويب؛ ليس كل صف عملية أعمال مستقلة. طرق closure وsettings خارج Presentation تحتاج مراجعة إضافية. هذا يمنع ادعاء مقام كامل قبل تصنيف الأفعال وربط المستهلكين.

| النوع | العدد | الدلالة |
| --- | ---: | --- |
| UI_ENTRY | 393 | مداخل public في مكونات الوحدات، تشمل أدوات واجهة وليست جميعها عمليات أعمال |
| HTTP_DOWNLOAD | 28 | مداخل Controllers؛ يلزم تحقق المحتوى والصلاحيات لكل تنزيل |
| OWNER_CONTRACT | 226 | دوال عقود مالك حالية؛ قد تقابل نفس العملية عبر أكثر من واجهة |
| ROUTE_INVENTORY | 166 | تعريفات Route الظاهرة في web.php؛ لا تشمل كل route ضمن ملفات أخرى |
| CATALOG_SCENARIO | 101 | صفوف كتالوج BUSINESS_SCENARIOS وDEEP_SCENARIO_CATALOG بحفظ معرفاتها ونص المتوقع |
| PRD_REQUIREMENT | 74 | صفوف PRD، تشمل صفوف النطاقات الملخصة؛ ليست 74 متطلباً فريداً |
| EXECUTION_SLICE | 8 | أدلة سابقة أو عوائق منفصلة، لا تعمم نتائجها على كل صف مصدر |

مصادر السياسات: [PRD](../MODULE_PRDS.md)، [العقود](../05-MODULE-CONTRACTS-EVENTS.md)، [السيناريوهات](BUSINESS_SCENARIOS.md)، [السيناريوهات العميقة](DEEP_SCENARIO_CATALOG.md). المصفوفات القديمة TEST_COVERAGE_MATRIX وV1_MODULE_COVERAGE_MATRIX تحفظ التاريخ، لكن عبارات غياب M07/M08/M11/M12 القديمة ليست حقيقة المصدر الحالي. M07 runtime هو Projects؛ DeliveryReturns هوية تاريخية لا صلاحية بديلة. لا يُعتبر نجاح guard على عملية غير متاحة نجاح تنفيذها.

## حقول القبول لكل عملية

CSV يربط معرف المصدر/السيناريو بالدور والصلاحية والنطاق، والبيانات الصناعية والمتوقع، والرفض، والاستقلال في الاعتماد، والأثر المالي/المخزني، والإلغاء والتصحيح، وإعادة المحاولة، والتصدير، والعربية وسطح المكتب والموبايل، ثم الاختبار والدليل والحالة. صلاحيات UI_ENTRY المأخوذة من المكون **مرشحات فقط**؛ تحديد الصلاحية الفعلية لكل مسار وحالة يستلزم قراءة Action المستدعى. الحقول العامة قوائم تحقق مطلوبة، وليست نتائج تنفيذ. لا يجوز تحويل عبارة «إن انطبق» إلى عدم انطباق دون تفسير موثق للعقد.

لإغلاق صف عملية: عين الدور والصلاحية الدقيقة وحالتي النطاق، أنشئ بيانات صناعية عبر actions/factories المالك، نفذ النجاح والرفض، واقرأ الحالة والمؤثرات مستقلاً ثم أعد الفتح. اربط assertion محدداً وreceipt بتوقيت وهدف قاعدة البيانات وtrace/صورة عند الحاجة. اختبر الاعتماد الذاتي والسياسة الحالية، الفشل الذري وإعادة الطلب، ثم الإلغاء/التصحيح والتصدير والعربية والكمبيوتر/الموبايل. ما لا ينطبق يحتاج سبباً؛ فتح الصفحة وحده لا يغطي الحفظ أو الترحيل.

## الحالات والتفريق

- EXECUTION_NOT_LINKED: مصدر موجود لكن لم يربط بعد باختبار ودليل خاصين بالفعل؛ لا يعني أن الاختبار غير موجود.
- HISTORICAL_CATALOG_NOT_RERUN وPRIOR_EXECUTION_PASS_NOT_RERUN: نص/دليل سابق محفوظ؛ لا إعادة تنفيذ في مهمة إعداد المصفوفة.
- ROUTE_ONLY: حصر طريق فقط.
- TEST_SETUP_FAILED: فشل جلسة/بيئة قبل العملية؛ لا يصنف عيب التطبيق.
- CHECK_FAILED_CAUSE_UNRESOLVED وTEST_SETUP_OR_TIMEOUT_UNRESOLVED: فشل مشاهد، السبب غير محسوم.
- SMART_EXECUTION_NOT_PROVEN: لا دليل رحلة ذكية ناجحة.
- NOT_IMPLEMENTED وTEST_MISSING وAPP_DEFECT_CONFIRMED: تستخدم فقط بعد فحص يثبت غياب تنفيذ، أو غياب اختبار بعد البحث، أو reproduction يثبت عيب التطبيق. لم تُسند تلقائياً من أسماء الملفات أو timeout.

## الأدلة المتاحة عند البداية

| الشريحة | الدليل والحكم المحدود |
| --- | --- |
| Laravel | checkpoint المستخدم 107 اختبارات/548 تحققاً عبر bootstrap-forms-ux --migrate --test؛ لم يُعد هنا؛ PHPUnit المباشر ليس بديلاً عن إيصال الملكية |
| مشتريات/مخزون/GL | [.tmp/valued-inventory-goal.log](../../.tmp/valued-inventory-goal.log): all_pass=true؛ 14 نقطة وفق checkpoint تشمل PDF وFIFO وGRNI والجزئي/النهائي والتراجع |
| مرتجعات | [.tmp/trade-returns-goal.log](../../.tmp/trade-returns-goal.log): خدمات/قاعدة بيانات، يتضمن serial وAR/GL/FIFO؛ لا قبول متصفح شامل |
| خزينة | .tmp/check-treasury-*-goal-verified.log: 121 تحققاً وفق checkpoint؛ rollback للبيانات؛ يلزم ربط كل حالة assertion |
| أوامر بيع | .tmp/sales-order-goal-check.log: 155 تحققاً وفق checkpoint؛ المصانع في ملف مهمل تحتاج مراجعة وجود تعديلات العقود |
| Critical Playwright | [.tmp/e2e-automation/latest-critical.json](../../.tmp/e2e-automation/latest-critical.json): 0/3؛ auth_state_creation_ms=8440007 مقابل database_bootstrap_ms=1982 وfixture_preparation_ms=3060؛ البطء مقاس في إنشاء جلسات المصادقة، لا في fixtures أو migration |
| صفحة موبايل | baseline 45/48 ثم نجاح document numbering في إعادة الفحص يجعل الاتحاد 46/48، لا إعادة تشغيل كاملة 46/48؛ العملاء والمرتجعات stalled بعد180 ثانية؛ السبب غير مثبت |
| EgyptMarketDemoSeeder | .tmp/egypt-market-fresh-check.log: فشل ملخص إدراج الجذور عند check:225؛ rollback/تنظيف ملفات؛ لا يثبت خطأ seeder قبل مقارنة inserts التابعة |
| TesterArmy | .tmp/testerarmy-smoke/report.json: فتح التطبيق السابق فقط؛ اعتماد الاشتراك والرحلات الجديدة تتابع لدى lead |

آثار Critical الأصلية تحت .tmp/e2e-automation/artifacts/55e2709649bd1f8e محفوظة. redirects إلى /login قبل خطوات العمل بحسب فحص traces الوارد من المستخدم؛ عمر الجلسة30 دقيقة يجعل الانتهاء فرضية قوية، ولا يحسم سبب 8440 ثانية في مرحلة المصادقة. يلزم إثبات بجلسة حديثة وفحص صلاحية قبل كل رحلة، مع إبقاء الدليل الأصلي.

## فجوات الاستئناف

1. تحويل صفوف الحصر إلى عمليات أعمال فريدة، مع ربط route → component method → owner Action/API → requirement. العقود المشتركة لا تعد عملية ثانية تلقائياً.
2. ربط كل صف بالدور والصلاحية الدقيقة؛ المرشحات المكونية لا تكفي لإثبات التفويض. استكمال Routes خارج web.php وclosures والواجهات الفرعية.
3. ربط الأدلة السابقة بكل assertion على حدة؛ صفوف الكتالوج التاريخية قد تحمل غياب producer صار منفذاً لاحقاً.
4. إعادة critical بجلسات حديثة وإثبات صلاحيتها؛ تشخيص العملاء والمرتجعات على الموبايل بأدلة جديدة؛ متابعة TesterArmy بعد التفويض.
5. مراجعة الحفظ وإعادة الفتح والرفض والسياسة والاعتماد والترحيل والتراجع والإلغاء والتصحيح وإعادة الطلب والتصدير والعربية لكل فعل. نجاح شريحة خدمات لا يغطي UI/الجوال.
6. استكمال Payroll/Reporting وأي capability غائبة/مؤجلة طبق المصدر والعقود دون اختراع نجاح؛ سياسة V2 التاريخية ليست إعفاءً تلقائياً من طلب التغطية الحالي.

لم تُشغّل قاعدة بيانات أو اختبارات أو commit في مهمة المصفوفة، ولم تُعدل بيانات التطبيق. يحدّث lead شرائح التنفيذ بالأدلة الجديدة؛ يبقى الهدف مفتوحاً حتى تغلق كل عمليات النطاق المطلوبة بنتائج قابلة للمراجعة.


## مراجعة ربط أسر الأفعال التجارية — 2026-11-11

[مصفوفة الأعمال](FULL_BUSINESS_OPERATION_MATRIX_2026-11-11.csv) قائمة محدودة من **100 صف: 97 أسرة Application و3 سجلات غياب مالك مستقل/هوية تاريخية**. هذه قائمة عمل، وليست 100 عملية أعمال فريدة؛ بعض methods قراءات أو helpers داخلية، والصفوف الثلاثة الأخيرة ليست أفعالاً منفذة. جرد الواجهة الأصلي ذو996 صفاً محفوظ دون تعديل.

راجعت صلاحية الربط في جميع الصفوف، وأزلت مراجع assertions وtest_sources غير المثبتة. المطابقة القديمة كانت تقبل تشابه method مثل execute/save/decide أو مجرد import صنف؛ لذلك ربطت SaveCompany خطأً برفض ائتمان في ApproveSalesOrder، وSupplierCategories بتعديل SupplierActions، وSalePrices بقرار استثناء تجاري. **هذه الروابط ليست دليلاً واستبعدت.** لم يُستنتج غياب اختبار من غياب ربط موثوق.

قاعدة الربط الحالية: استدعاء الصنف والـmethod نفسه، أو caller متصل صراحةً مع توثيق سلسلة API/binding/service، ثم assertion يقرأ نفس الناتج أو الحالة في نفس السيناريو. mapped_methods يحد النطاق؛ mapping_chain يثبت المتصل؛ test_sources يحمل file:line. تمت مقارنة methods المعلنة بجميع ملفات الأسر97، مع إبقاء helpers العامة مثل hash/fail ضمن الحصر فقط. وجود assertion في المصدر لا يثبت إعادة تشغيله، ووجود سجل قديم لا يثبت مطابقته لبصمة المصدر الحالية.

| الحالة الحالية | الأسر | الحكم |
| --- | ---: | --- |
| NARROW_ASSERTION_SOURCE_NOT_RERUN | 3 | ربط يدوي محدود بالمصدر، دون إعادة تنفيذ أو قبول أسرة كاملة |
| ASSERTION_MAPPING_NOT_VERIFIED | 69 | لا ربط تحقق موثوق محفوظ، ويتطلب مراجعة العملية والسيناريو |
| NARROW_ASSERTION_EXECUTED | 25 | تشمل عمليات محددة في الشركة والفروع والكتالوج والحضور والورشة والبيع والشراء والمخزون والفواتير والمرتجعات والصيانة والخزينة والترقيم |
| OWNER_NOT_IMPLEMENTED_CONFIRMED | 3 | غياب مالك Payroll وReporting المستقلين وهوية DeliveryReturns التاريخية؛ لا ينفي أفعال owners آخرين |
| إغلاق قبول كامل لأسرة بهذه المراجعة | 0 | صلاحيات/حالات/متصفح/أثر وبصمة تنفيذ لم تكتمل لكل أسرة |

أضيف acceptance_priority: P0 للأمن والبيع/الشراء والمخزون والمال والخزينة، P1 للأعمال التشغيلية، وP1_REQUIREMENT_GAP لسجلات غياب المالك. الأولوية ترتيب استكمال، لا نتيجة نجاح. role وscope غير المحسومين مصرح بهما؛ source authorize ليس إثبات دور أو اختبار نطاق. حُل مفتاح M01.security_admin لمجموعة أفعال RbacGuard دون ادعاء تغطية فروع target/self/last-administrator. RevokeUserSessions helper بلا actor في توقيعه؛ يلزم تفويض caller، ولا يعامل وحده كنقطة دخول مأمونة أو معيبة.

Laravel أعيد اليوم لدى lead: **107 اختبارات/548 تحققاً**؛ تمت قراءة خاتمة `.tmp/coverage-resume-20261111/laravel-107-548.log` وفيها107 passed و548 assertions و46.67s. هذه نتيجة suite؛ لم يُفترض منها تغطية أي أسرة غير مربوطة.

### الروابط التي أبقيت بعد قراءة السيناريو

| الأسرة | الاستدعاء والـassertion المحددان | حد الدليل |
| --- | --- | --- |
| Projects/ProjectActions | `tests/Support/check-phase2-projects.php:110` يربط $actions؛ save عند113/116/128 وحالة/version عند114/117/129؛ taskStatus عند136 و403 عند139؛ completion عند203 و25% عند206 | أعضاء المشروع/النطاق على ProjectAccess لا يعدون اختبار save؛ «reopened draft» عند129 تعديل آخر للمسودة، وليس lifecycle reopen |
| Projects/ProjectPlanning | نفس check عند151 يربط $planning؛ decide162/403 ثم164/166 اعتماد مستقل؛ transition255/257 completed،258/260 closed،263/265 active | الإكمال والإغلاق وإعادة الفتح تخص ProjectPlanning؛ financial projections هنا فارغة، لا تثبت تسوية التزامات غير صفرية |
| Projects/ProjectExecution | نفس check عند144/147 ثم148 milestone completion/actor؛232 ثم234 dependency persisted | نجاح محدود فقط؛ رفض taskStatus عند236 ملك ProjectActions؛ لا ينسب إلى ProjectExecution |
| Sales/ApproveSalesOrder | `tests/Support/check-phase0-valued-inventory.php:837` replay ثم838–840 issue واحد/reservations ثابتة؛843–844 altered warehouse conflict | السطر833 يختبر InventoryApi::fulfillReservation واستبعد من ربط اعتماد البيع؛ لا تدعي هذه الأسطر كل سياسة الاعتماد |
| Purchasing/PurchaseOrders | نفس check923 يربط $orders؛925–929 save/submit/independent approval/issue؛927 self approve denied؛931 issue exists؛970–971 reported tax denied/no issue | لا قبول شامل لفروع transition أو UI |
| Purchasing/PurchaseReceiptPosting | نفس check992 ثم993–994 denied/no movement؛1017 ثم1027–1028 atomic rollback؛1035 ثم1038 capitalized0.34؛1040 ثم1041 one movement | المتصل `app/Application/PostPurchaseReceipt.php:15` و22 → `PurchasingService.php:57` و62؛ binding في `app/Providers/AppServiceProvider.php:130`؛ لا مجرد تشابه execute |
| Inventory/ValuedCorrections | نفس check390 يربط InventoryCorrections؛494 reverse ثم496–498 original FIFO layer/-3 | `InventoryCorrections.php:50` يتصل ValuedCorrections::post. رفض unauthorized/remaining/replay/dependency يخص caller قبل helper؛ لا ينسب كرفض مباشر للـhelper |
| Sales/SalesReturns | `tests/Support/check-trade-returns.php:476` يربط $sales؛508–510 same-ID/no stock؛515–529 stock+1.5/two FIFO/cost15/credit/AR/replay؛546–547 self deny بعد grant؛554–555 full credit | الصلاحيات M04.sales_order_return_*؛ لا cross-branch أو UI pass من هذه الأسطر |
| Purchasing/PurchaseReturns | نفس check477 يربط $purchases؛561–562 exhausted layer/draft unchanged؛578 self deny؛579–584 stock-1/inventory-10/net11/tax1.54/AP12.54/PPV-1 | reject عند563 مستدعى دون assertion مستقل لحالة rejected؛ لا يغطي تصحيح posted return |
| ReceivablesPayables/CustomerAdvances | `tests/Support/check-treasury-advance-lifecycle.php:138` يربط API؛141–145 apply15/replay/one row؛148–153 altered/date/balance/permission/foreign-branch denials وsnapshot ثابت | binding `AppServiceProvider.php:127` → `ReceivablesPayablesService.php:68` → CustomerAdvances::apply؛ cutoff عند140 يخص reconciliationValues ولا ينسب إلى apply |
| System/DocumentNumbering | `tests/Support/check-trade-returns.php:566` setStart500 ثم579/585 document ends000500؛586 reset900 denied | setStart يحتاج M01.company_update من المصدر؛ الاختبار ليس permission-denial أو concurrency coverage |

### الأدلة السابقة المقروءة وحدودها

قرئت سجلات `.tmp/valued-inventory-goal.log` و`.tmp/trade-returns-goal.log`: يحفظان all_pass=true والحقول المحددة للشرائح أعلاه؛ لم يعادا بهذه المراجعة، ولم تربط بصمة المصدر الحالية بكل receipt. سجل `.tmp/check-treasury-advance-lifecycle-goal-verified.log` يحفظ24 assertions وall_pass=1 وrollback_verified=1 وإيصال `docs/release/remediation/evidence/gate5/treasury-advance-20261110T185136Z-2d780b.json`؛ هذا يخص شريحة المقدمات فقط، ولا يبرر تعميم121 تحققاً على كل الخزينة. لا سجل تنفيذ جديد ربط هنا لسيناريو Projects. أي ملخص سابق أوسع في جدول البداية يبقى checkpoint منقولاً إلى أن يراجع مصدره وربطه؛ ليس قبولاً جديداً.

### مالكو النطاق غير المكتمل

المصدر يحوي15 مجلد owner. لا `app/Modules/Payroll` أو `app/Modules/Reporting`؛ **Organization/EmployeeActions لا يمثل payroll**. التقارير الحالية موزعة لدى Accounting/Attendance/Projects/ServiceContracts/WorkshopRepairs وControllers التنزيل. `DeliveryReturns` historical M07 مسجلimplemented=false في config/modules.php، بينما runtime Projects=M07؛ المرتجعات والتسليم الحالية لدى Sales/Purchasing. لا يحول غياب اسم owner إلى غياب كل قدرة، ولا يعفي متطلبات غير منفذة من الاستكمال.

لم ينفذ مراجع المصفوفة اختبارات أو قاعدة بيانات ولم يكتب test/check code أو يغير التطبيق. التغيير محصور في وثيقتي الأعمال هذه. **الهدف الكامل مفتوح:** يلزم تقسيم الأسر إلى عمليات فريدة وربط كل method/فرع وصلاحية ونطاق وassertion وإيصال صالح، ثم قبول الرحلة والعربية والكمبيوتر/الموبايل.11 روابط مصدر ضيقة ليست11 عمليات مكتملة.
