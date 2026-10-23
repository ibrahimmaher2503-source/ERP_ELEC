# Round 2 — سجل العيوب

هذا سجل عيوب الجولة الثانية؛ [سجل الجولة الأولى](DEFECT_REGISTER.md) محفوظ. لا تعد أخطاء تجهيز Playwright/fixtures أو وجود dependencies غائبة عيوب تطبيق مؤكدة. حالة الإغلاق النهائية مرتبطة بإعادة الفحص في [التقرير](QA_ROUND_2_FINAL_REPORT.md).

| ID / الشدة | إعادة الإنتاج والمتوقع مقابل الفعلي | السبب والإصلاح | Regression / الأدلة |
| --- | --- | --- | --- |
| R2-Q-001 / HIGH | افتح draft valid_until=NULL، عدل واحفظ؛ المتوقع نفس المسودة محفوظة؛ الفعلي Livewire 500 وSQLSTATE22007 | mount يعرض NULL كـ`''`، Action مرر النص إلى date؛ تطبيع optional date في QuotationActions بعد التحقق | Q + BQ؛ `quotation-reopen/beforefix/result.json` مع 500 وDB version=1؛ النتائج النهائية في E2E |
| R2-Q-002 / HIGH | نفس component: حفظ ثم تعديل وحفظ؛ المتوقع نفس id ونسخة حالية؛ الفعلي stale version، وفي new component ظل recordId=null ما يسمح بإنشاء صف ثان | save لم يحدث locked recordId/version؛ تحديثهما فور نجاح Action | Q؛ assertions `A second Save...` و`Repeated Save on a new Livewire draft...` |
| R2-UI-001 / MEDIUM | Save ناجح ثم redirect؛ المتوقع انتقال بلا تحذير؛ الفعلي confirm لتعديلات غير محفوظة | redirect effect يسبق dispatch بعد morph؛ اعتراض successful save redirect يمسح dirty قبل navigate؛ cleanup عند destroy | BQ؛ `quotation-reopen/afterfix/result.json` يثبت حفظ DB مع التحذير قبل إصلاح UI؛ السلوك المشترك أصلح في quotation وorder views |
| R2-PARTY-001 / MEDIUM | أرسل NUL في phone أوcity لcontact/address عميل ومورد؛ المتوقع ValidationException قبل الكتابة؛ الفعلي input قُبل وسجل تغير | validators الفرعية لم تحمل guard النصوص الموجود في root؛ إضافة not_regex للنصوص المعلنة في CustomerActions/SupplierActions | M؛ before-fix JSON و36 assertion passing بما فيها no mutation/no success audit؛ readback الأول في الدليل كان model attributes وليس دليل تخزين NUL حرفيًا |
| R2-SO-001 / MEDIUM | requested_delivery_date فارغ من form/reopen؛ المتوقع SQL NULL؛ Action كان يمُرر `''` إلى date | نفس optional-date boundary في SalesOrderActions؛ normalized after validation | SO وC يثبتان NULL؛ قبل الإصلاح المباشر لم يُحفظ browser reproduction مستقل، لذلك لا يساوى مستوى إثبات R2-Q-001 |
| R2-CATALOG-001 / HIGH | غيّر منتج بند إلى منتج مختار سابقًا خارج productSearch الحالي؛ المتوقع تحديث الوحدة والوصف والسعر؛ الفعلي بقيت الوحدة القديمة بعد Livewire200 | updatedLines بحث داخل أول صفحة/فلتر فقط بينما render يعرض المنتجات المختارة أيضًا؛ lookup بالهوية عبر ProductsApi مع active guard في quotation/order editors | Q وSO؛ قبل الإصلاح assertion mismatch unit97≠96؛ المتصفح كشف mismatch94≠93؛ إعادة الفحص تشمل lookup وتصفير السعر وحفظ القيم |

ملفات التطبيق المعدلة: `app/Modules/Sales/Application/{QuotationActions,SalesOrderActions}.php`، `app/Modules/Sales/Presentation/{QuotationFormPage,SalesOrderFormPage}.php`، `resources/views/sales/{quotations,orders}/form.blade.php`، `app/Modules/Parties/Application/{CustomerActions,SupplierActions}.php`.

الـstack trace الأصلي الكامل لاختبار العطل المبلغ عنه محفوظ في `round2/evidence/quotation-reopen/beforefix/laravel-action-exception.txt`. بعض المحاولات الأولى لأخطاء state/dirty لم تُحفظ في ملفات مستقلة قبل تحديث harness؛ تسجل ملاحظات الجولة دون اختلاق snapshot مفقود، وتظل اختبارات الرجوع الدائمة هي دليل ما بعد الإصلاح.

تحسينات harness: M01 singleton fixture لم يعد يفترض قاعدة فارغة؛ views للمتصفح معزولة؛ router يخدم CSS/JS الفعليين؛ selectors صارت تطابق Livewire modifiers. هذه ليست إصلاحات business rules، ولا تُحسب ضمن عيوب التطبيق المؤكدة.
