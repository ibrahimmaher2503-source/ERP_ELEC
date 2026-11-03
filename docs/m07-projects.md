# M07 Projects — PRJ-01 through PRJ-11

## Current Phase 2 acceptance — 2026-10-29

**PHASE 2 COMPLETE — READY FOR M08 FIELD MAINTENANCE**. Implementation, exact acceptance results, current contracts/routes and remaining allowed boundaries are in [PHASE2_PROJECTS_REPORT.md](release/PHASE2_PROJECTS_REPORT.md). The checkpoints below are historical and superseded where they conflict with the Phase 2 owner instruction or accepted implementation.

Budget changes now require independent approval; the Phase 2 instruction supersedes the earlier PRJ-06 manager self-approval allowance. Assignment alone does not grant sensitive permissions. Real owner APIs now provide material reservation/FIFO issue, source financial attribution and reconciliation, handover and closure. Financial history stays immutable, and closing a project does not settle its M12 open items. Historical sources are not retroactively attributed. M08/M15 implementation remains outside this phase.

## Earlier requirements and checkpoints

Owner prompt 2026-10-22 is authoritative. Projects owns master, membership, phases/tasks, equal-phase progress, planning budgets/baselines, change proposals, private document revisions and closure evidence. Images govern appearance only.

Implementation order: master and scoped membership → phases/tasks → lifecycle → budget/baselines and change requests → documents → supported source links/material requirements → reports and closure evidence.

Authorization: current active verified account and active branch scope; explicitly assigned manager controls only their project; members can view their project and update only assigned tasks. Other actors need registered M01 capabilities and branch scope. Manager self-approval of project changes is expressly allowed (PRJ-06), distinct from purchasing self-approval policy. No implicit Accounting or Inventory rights.

Progress is calculated from completed non-cancelled tasks, then the equal mean of non-cancelled phases, using BCMath. No tasks/phases means zero. Completed requires eligible phases at 100%; Closed requires external evidence and is blocked while Inventory/cost settlement contracts are unavailable.

Master identity/customer/manager/branch are draft-controlled. Baseline scope/phase changes require formal revisions after planning. No destructive history deletion. Version checks and row locks serialize mutations; creation UUID prevents duplicate submissions. Append-only activity stores actual actors and safe before/after snapshots.

Actual cost and inventory producers are not yet evidenced for projects: display unavailable, never verified zero. Commercial links must use owner APIs, validate customer, preserve identities and avoid duplicate recognition.

Automated tests deferred by standing owner instruction. No QA acceptance claimed.

## Implementation checkpoint — 2026-10-22

IMPLEMENTED — QA DEFERRED: incremental migrations 147–151 applied locally; project list/create/draft-edit, assigned manager/team controls, separate phases/tasks page, task edits/moves/reopen/cancel, equal-phase progress, team updates with open-task removal protection, explicit lifecycle to Planned/Active/Hold/Completed, budget category and optional existing-phase allocation, immutable baselines, manager-reviewed scope/budget/phase-structure proposals, draft/review/approve/reject private document revisions, authorized downloads, readonly Sales/Purchasing source references, material requirement planning, dashboard, progress report and private text-safe Excel export, closure evidence screen.

Organization exposes active verified branch-assignee names and current branch scopes through its public contract. Purchasing exposes a safe current-state document projection through PurchasingApi. Projects never queries other owners' records. Source identity is unique across projects; customer consistency applies to customer-owned Sales sources, branch consistency to branch-owned sources. No source amount is summed as revenue or actual cost.

Project activities are append-only owner-local snapshots with real actor IDs. New registered permissions participate in the existing Administrator synchronization after migrations; other roles remain manually configured. ManagerReadOnly may receive only M07 view/report/export keys.

Factories cover Project, Phase, Task, Requirement, Document, Draft Revision and Pending Change. Approved baselines and review/activity history are action-generated, not seeded. ProjectsDemoSeeder is explicitly invoked/local-testing only and composes three recognizable DEMO-EG draft graphs, disabled fixture managers, planning tasks/materials and private tiny illustrative draft images; no approval, stock, cost, credential or privilege is fabricated. EgyptMarketDemoSeeder calls it in its existing composition. Existing synthetic records/edits are preserved.

Actual commands completed: migrations 147–151, explicit ProjectsDemoSeeder invocation, scoped source formatting, Blade compilation and Vite production build. No automated suites or browser workflow acceptance performed. Existing missing Noto font-file build warnings remain. UI is reference-guided, not verified screenshot parity.

PARTIALLY IMPLEMENTED: broader report families, rich per-source financial commitment projections, customer contract registry links, project inventory reservation/issue integration, actual recognized cost integration, cancellation/closure execution and the full ten-screen visual parity are not accepted. Current Sales/Purchasing links expose current state and navigation only. Closure/cancellation stay unavailable until source-owned obligations, reservation cleanup and cost reconciliation evidence exist. No customer collection prerequisite was added. No speculative financial or stock ledger was created.


## سجل المرحلة التفصيلي — 2026-10-22

الحالة الإجمالية: **PARTIALLY IMPLEMENTED**. الوظائف الموجودة: **IMPLEMENTED — QA DEFERRED**؛ وجود الكود والترحيلات لا يثبت قبول مسارات التشغيل أو المطابقة البصرية. هذا هو ملف متابعة M07 المخصص، ولا يعدّل ملفات `.ai`.

### المنفذ

| المجال | ما أضيف فعليًا | حدود التنفيذ |
|---|---|---|
| التأسيس | توثيق PRJ-01 إلى PRJ-11، صلاحيات M07، ترحيلات PostgreSQL من 147 إلى 151 | محلي فقط |
| المشروع | إنشاء مسودة، حفظ وتعديل المسودة، كود فريد، عميل رئيسي واحد، مدير، فرع، تواريخ، نطاق وملاحظات | الحقول الأساسية مقيدة بعد خط الأساس؛ لا يُدعى تحقق الحفظ المتكرر بالمتصفح |
| التفويض والفريق | مدير معين لكل مشروع، عضوية الفريق، نطاق الفرع، صلاحيات M01، تحديث الفريق، منع إزالة صاحب مهام مفتوحة قبل إعادة توزيعها | لا يمنح صلاحيات محاسبة أو حركة مخزون؛ حسابات التجربة معطلة |
| المراحل والمهام | صفحة مستقلة، إنشاء مراحل مسودة، إنشاء وتعديل ونقل المهام، التكليف، البدء والإكمال وإعادة الفتح والإلغاء | تغيير الهيكل بعد اعتماد خط الأساس عبر طلب تغيير؛ لا حذف تاريخي |
| الإنجاز | نسبة المرحلة من المهام المكتملة غير الملغاة، ونسبة المشروع من متوسط المراحل غير الملغاة بالتساوي | بلا مراحل/مهام مؤهلة = صفر؛ لا تعديل يدوي للإنجاز |
| دورة الحياة | Draft → Planned → Active → Completed، الإيقاف المؤقت والاستئناف وإعادة فتح العمل | التخطيط يحتاج خط أساس، والإكمال يحتاج كل المراحل والمهام؛ Closed وCancelled غير متاحين للتنفيذ النهائي |
| الميزانية | إجمالي بعملة محددة، فئات يحددها المستخدم، توزيع اختياري على المراحل الموجودة، تحقق تطابق المجاميع باستخدام BCMath | توزيعا الفئات والمراحل تمثيلان لنفس الإجمالي؛ لا حساب تكاليف فعلية من الميزانية |
| خط الأساس والتغييرات | مقترحات ميزانية ونطاق وهيكل مراحل، قرار مدير المشروع، سبب وفاعل وتوقيت، نسخ معتمدة غير قابلة للتعديل | المقترح المعلق لا يغير النسخة الفعالة؛ اعتماد المدير لمقترحه مسموح بقرار PRJ-06 |
| المستندات | رفع مسودة PDF/صورة، مراجعة، اعتماد/رفض المدير، مراجعة جديدة، تاريخ النسخ، تحميل مصرح من تخزين خاص | لا استبدال النسخة المعتمدة، ولا توقيعات أو إقرارات مصطنعة؛ العارض المضمن والتجربة البصرية غير مقبولين QA |
| الارتباطات | عروض أسعار، أوامر بيع، احتياجات شراء، RFQ، عروض موردين، أوامر شراء واستلامات عبر عقود المالك | تحقق العميل لمستندات البيع والفرع للمصادر ذات الفرع؛ تعرض الحالة الحالية والتنقل دون احتساب إيراد أو تكلفة |
| المواد | حفظ احتياجات أصناف فعلية من M02 بوحدتها الأساسية وكمياتها التخطيطية | لا حجز أو صرف فعلي من M07 |
| العرض والتقارير | لوحة مشروعات، قائمة وبحث وحالات، تقرير إنجاز وميزانية، تصدير Excel خاص، صفحة أدلة الإغلاق | التقارير الموسعة والمطابقة الكاملة للتصاميم العشرة غير مكتملة |
| التدقيق | سجل أنشطة محلي append-only، فاعل حقيقي، لقطات قبل/بعد، إصدارات وأقفال للعمليات | لم تُختبر حالات التزامن أو محاولات التجاوز ضمن هذه الدفعة |
| بيانات التجربة | Factories للمشروع والمرحلة والمهمة والمتطلب والمستند والمراجعة المسودة وطلب التغيير المعلق؛ ProjectsDemoSeeder وثلاثة مشروعات DEMO-EG | لا اعتماد أو حركة أو تكلفة أو صلاحيات أو بيانات دخول مصطنعة؛ النسخ المعتمدة والسجل ينتجان من Actions |

### سجل العوائق والتبعيات

| المعرف | العائق الحالي | أثره | المطلوب لرفعه |
|---|---|---|---|
| M07-B01 | لا يوجد عقد M06 مكتمل لطلب المشروع وحجزه وصرفه وتسويته | المحجوز والمصروف يظهران غير متاحين؛ احتياجات المواد تظل تخطيطًا | عقد مالك يتحقق من هوية الطلب ونطاق المستودع، يمنع تكرار طلب المشروع/البيع، ويعيد أدلة الحجز والصرف والتحرير |
| M07-B02 | عقد التكاليف المعترف بها والمطابقة المالية غير منفذ | التكاليف الفعلية والانحراف والمتبقي لا تحسب من أصفار مفترضة؛ الإغلاق المالي محجوب | إسقاط مالي مصرح بعملة ومصدر وحالة اعتراف ومطابقة واضحة، دون دفتر موازٍ في M07 |
| M07-B03 | لا توجد أدلة مصدرية شاملة لتسوية التزامات المشتريات والعمليات | الإغلاق والإلغاء النهائيان غير متاحين | عقود تسوية من المالكين تراعي المستندات المفتوحة والآثار التاريخية؛ مجرد الربط لا يثبت التسوية |
| M07-B04 | سجل العقود التجارية أو عقد قراءته غير متاح في التنفيذ الحالي | ارتباطات العقود وقيمها غير مكتملة | تحديد/تنفيذ إسقاط مصرح من مالك العقود؛ لا إنشاء نظام عقود تجارية كامل داخل M07 دون اختصاص معتمد |
| M07-B05 | إسقاط الالتزامات المالية التفصيلي من المصادر غير مكتمل | روابط المشتريات تعرض الحالة فقط، دون تقرير التزامات مالية شامل | تعريف القيم المفتوحة والعملة والمصادر ومنع العد المزدوج، عبر عقود المالك |
| M07-B06 | تحذيرات بناء لملفي Noto العربي واللاتيني المشار إليهما تحت public/fonts | تحميل الخط المقصود والمطابقة البصرية غير مضمونين | توفير ملفات الخط القانونية المطلوبة أو تصحيح مراجع الأصول والتحقق بصريًا |

لا يوجد قرار مالك جديد مثبت كعائق عام لبناء M07. صلاحيات المدير، طريقة الإنجاز، وتفويض اعتماد تغييرات المشروع محسومة بالفعل. التبعيات أعلاه تخص العمليات المتأثرة فقط، ولا تمنع استكمال الواجهات والتخطيط والتقارير المتاحة.

### أعمال متبقية وليست عوائق أعمال

- استكمال عائلات التقارير المتخصصة ومؤشراتها حسب البيانات والعقود المتاحة، دون نسب أو مقارنات زمنية مصطنعة.
- تحسين الاختيار والبحث في ارتباطات المصادر والأصناف؛ الواجهة الحالية تسمح بربط معرفات داخلية صحيحة مع تحقق الخادم.
- استكمال فصل وترتيب شاشات المبيعات/المشتريات والمخزون والتكلفة والتفاصيل وفق مراجع الشاشات العشرة.
- استكمال عرض المقارنات التفصيلية بين نسخ خط الأساس ومعاينة المستندات وتجربة مراجعاتها.
- استكمال عقود المخزون والتكاليف والتسوية ثم توصيل إجراءات الإغلاق والإلغاء الحقيقيين.
- تجربة إنشاء → حفظ → خروج → إعادة فتح → تعديل → حفظ → إعادة تحميل لكل نموذج، والتحقق من استمرار البيانات والصلاحيات والتزامن.
- التحقق من RTL/LTR والموبايل ولوحة المفاتيح وحالات التحميل والفراغ والأخطاء ومطابقة الصور بنفس حجم العرض.

### أدلة التنفيذ وحدود التحقق

| الإجراء | النتيجة المسجلة |
|---|---|
| php artisan migrate --force | ترحيلات 147–151 نُفذت محليًا |
| php artisan db:seed --class=ProjectsDemoSeeder --force | نُفذ؛ ثلاثة مشروعات مسودة تجريبية وعلاقاتها دون منح صلاحيات أو اعتماد أو ترحيل |
| تنسيق الملفات المستهدفة باستخدام Pint | نُفذ بعد إصلاح تمرير wildcard غير المدعوم في أحد الاستدعاءات |
| php artisan view:cache | نجح بعد إصلاح تعارض اسم دالة transition مع Livewire، وإصلاح صيغة foreach |
| php artisan route:list --path=projects --except-vendor | عرض 12 مسارًا مسجلًا |
| npm run build | نجح؛ تحذيرات ملفات الخط المذكورة ما زالت موجودة |
| الاختبارات الآلية وقاعدة PostgreSQL الاختبارية | لم تُنفذ ضمن هذه الدفعة؛ مؤجلة بتعليمات المالك |
| الحفظ المتكرر والصلاحيات عبر متصفح مسجل الدخول | NOT TESTED |
| الصور والموبايل والمطابقة البصرية | NOT TESTED |
| قبول QA / النشر / تفعيل الإنتاج | لم يحدث |

### مواقع التنفيذ للاستكمال

- العقد والمتابعة: `docs/m07-projects.md`، والإشارة العامة في `EXECUTION_PIPELINE.md`.
- منطق وواجهات المالك: `app/Modules/Projects/{Application,Domain,Presentation}`.
- قوالب العرض: `resources/views/projects`؛ المسارات في `routes/web.php`.
- عقود التنظيم: `app/Modules/Organization/Application/Contracts/OrganizationApi.php` وتنفيذها في OrganizationService.
- إسقاط المشتريات: PurchasingApi وPurchasingService داخل مالك Purchasing، وربطهما في AppServiceProvider.
- الترحيلات: الملفات ذات التوقيت 147000 إلى 151000 بتاريخ 2026-10-22.
- بيانات التجربة: `database/factories/Project*Factory.php`، `database/seeders/ProjectsDemoSeeder.php`، وتركيبها داخل EgyptMarketDemoSeeder.

أولوية الاستكمال: مسارات الحفظ والتفويض عند طلب التحقق، ثم تحسين الشاشات والتقارير المتاحة، ثم تفعيل التكاملات المتأثرة عند توفر عقودها وأدلتها. لا تُسجّل أي تبعية أو نموذج عرض على أنه تشغيل مكتمل.
