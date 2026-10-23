<?php

return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'حقل :attribute يجب أن يكون نصًا.',
    'max' => ['string' => 'حقل :attribute يجب ألا يزيد عن :max حرفًا.', 'file' => 'حجم :attribute يجب ألا يزيد عن :max كيلوبايت.'],
    'image' => 'ارفع ملف صورة صالحًا.',
    'mimes' => 'الصيغ المسموحة: :values.',
    'dimensions' => 'أبعاد الشعار يجب ألا تتجاوز 3000 × 3000 بكسل.',
    'email' => 'أدخل عنوان بريد إلكتروني صالحًا.',
    'confirmed' => 'تأكيد :attribute غير متطابق.',
    'min' => ['string' => 'حقل :attribute يجب ألا يقل عن :min حرفًا.'],
    'integer' => 'حقل :attribute يجب أن يكون رقمًا صحيحًا.',
    'exists' => 'قيمة :attribute غير موجودة.',
    'in' => 'اختر قيمة صحيحة لحقل :attribute.',
    'prohibited' => 'لا يمكن تعديل :attribute من هذا النموذج.',
    'password' => [
        'mixed' => 'كلمة المرور يجب أن تحتوي على حرف كبير وحرف صغير.',
        'numbers' => 'كلمة المرور يجب أن تحتوي على رقم.',
        'letters' => 'كلمة المرور يجب أن تحتوي على حروف.',
    ],
    'not_regex' => 'حقل :attribute يحتوي على محارف غير مسموحة.',
    'unique' => 'قيمة :attribute مسجلة مسبقًا.',
    'regex' => 'حقل :attribute يحتوي على محارف غير مسموحة.',
    'attributes' => [
        'code' => 'الكود', 'name' => 'الاسم', 'legal_name' => 'الاسم القانوني',
        'tax_registration' => 'رقم التسجيل الضريبي', 'address' => 'العنوان',
        'email' => 'البريد الإلكتروني', 'branch_id' => 'الفرع الرئيسي', 'password' => 'كلمة المرور',
        'gender' => 'النوع', 'form.gender' => 'النوع',
        'form.name' => 'الاسم', 'form.email' => 'البريد الإلكتروني', 'form.branch_id' => 'الفرع الرئيسي', 'form.password' => 'كلمة المرور',
    ],
];
