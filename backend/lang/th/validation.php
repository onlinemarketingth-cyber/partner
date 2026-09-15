<?php

/*
 * 2026-09-15 — Laravel's own validation messages, in Thai.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Every message this application writes itself is already Thai — Form
 * Requests, Services, Policies, the lot. The messages the FRAMEWORK writes
 * were not, and nobody noticed, because they only appear on the paths where
 * something went wrong: a field left blank, a file too large, a date typed in
 * the wrong order. So an agent filling in a form correctly saw Thai all the
 * way through, and the moment they made a mistake the screen answered in
 * English. The refusal is exactly the moment the reader most needs to
 * understand it.
 *
 * lang/th/auth.php has sat here since TASK-247 with a note saying it was "not
 * wired to auto-switch yet — APP_LOCALE is 'en'". This is the other half of
 * that: `config/app.php` now defaults the locale to 'th', with 'en' still the
 * fallback, so a key missing from this file degrades to the English sentence
 * rather than printing the raw key name.
 *
 * ── HOW THE :placeholders WORK, AND WHY THEY SURVIVE TRANSLATION ──
 *
 * `:attribute` is the field name and `:min`/`:max`/`:other`/… are the rule's
 * own values. Laravel substitutes them by name, not by position, so a Thai
 * sentence may put them in a different order than the English one — and must
 * keep the identical spelling. A typo in a placeholder is silent: the
 * sentence renders with a literal ":attribuet" in it and nothing errors.
 *
 * `:attribute` falls back to the field's own column name ("bank_account_number")
 * unless a Form Request names it in attributes(). That is why several of the
 * sentences below are written to read acceptably even when the subject is an
 * English identifier — and why the `attributes` array at the bottom carries
 * the names that appear across many forms rather than each Request repeating
 * them.
 */

return [
    'accepted' => 'ต้องยอมรับ :attribute',
    'accepted_if' => 'ต้องยอมรับ :attribute เมื่อ :other คือ :value',
    'active_url' => ':attribute ไม่ใช่ URL ที่ใช้งานได้',
    'after' => ':attribute ต้องเป็นวันที่หลัง :date',
    'after_or_equal' => ':attribute ต้องเป็นวันที่ตรงกับหรือหลัง :date',
    'alpha' => ':attribute ต้องเป็นตัวอักษรเท่านั้น',
    'alpha_dash' => ':attribute ต้องเป็นตัวอักษร ตัวเลข ขีดกลาง หรือขีดล่างเท่านั้น',
    'alpha_num' => ':attribute ต้องเป็นตัวอักษรและตัวเลขเท่านั้น',
    'any_of' => ':attribute ไม่ถูกต้อง',
    'array' => ':attribute ต้องเป็นรายการ (array)',
    'ascii' => ':attribute ต้องเป็นตัวอักษรและสัญลักษณ์แบบอังกฤษเท่านั้น',
    'before' => ':attribute ต้องเป็นวันที่ก่อน :date',
    'before_or_equal' => ':attribute ต้องเป็นวันที่ตรงกับหรือก่อน :date',
    'between' => [
        'array' => ':attribute ต้องมีจำนวน :min ถึง :max รายการ',
        'file' => ':attribute ต้องมีขนาด :min ถึง :max กิโลไบต์',
        'numeric' => ':attribute ต้องอยู่ระหว่าง :min ถึง :max',
        'string' => ':attribute ต้องมีความยาว :min ถึง :max ตัวอักษร',
    ],
    'boolean' => ':attribute ต้องเป็นค่าจริงหรือเท็จเท่านั้น',
    'can' => ':attribute มีค่าที่ไม่ได้รับอนุญาต',
    'confirmed' => ':attribute ยืนยันไม่ตรงกัน',
    'contains' => ':attribute ขาดค่าที่จำเป็น',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'date' => ':attribute ไม่ใช่วันที่ที่ถูกต้อง',
    'date_equals' => ':attribute ต้องเป็นวันที่ตรงกับ :date',
    'date_format' => ':attribute ไม่ตรงกับรูปแบบ :format',
    'decimal' => ':attribute ต้องมีทศนิยม :decimal ตำแหน่ง',
    'declined' => 'ต้องปฏิเสธ :attribute',
    'declined_if' => 'ต้องปฏิเสธ :attribute เมื่อ :other คือ :value',
    'different' => ':attribute กับ :other ต้องไม่ซ้ำกัน',
    'digits' => ':attribute ต้องเป็นตัวเลข :digits หลัก',
    'digits_between' => ':attribute ต้องเป็นตัวเลข :min ถึง :max หลัก',
    'dimensions' => ':attribute มีขนาดภาพไม่ถูกต้อง',
    'distinct' => ':attribute มีค่าซ้ำกัน',
    'doesnt_contain' => ':attribute ต้องไม่มีค่าใดใน :values',
    'doesnt_end_with' => ':attribute ต้องไม่ลงท้ายด้วย :values',
    'doesnt_start_with' => ':attribute ต้องไม่ขึ้นต้นด้วย :values',
    'email' => ':attribute ต้องเป็นอีเมลที่ถูกต้อง',
    'encoding' => ':attribute ต้องเป็นข้อความที่เข้ารหัสแบบ :encoding',
    'ends_with' => ':attribute ต้องลงท้ายด้วยค่าใดค่าหนึ่งใน :values',
    'enum' => ':attribute มีค่าที่ไม่ถูกต้อง',
    'exists' => 'ไม่พบข้อมูลที่เลือกใน :attribute',
    'extensions' => ':attribute ต้องเป็นไฟล์นามสกุล :values',
    'file' => ':attribute ต้องเป็นไฟล์',
    'filled' => 'ต้องระบุ :attribute',
    'gt' => [
        'array' => ':attribute ต้องมีมากกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดมากกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องมากกว่า :value',
        'string' => ':attribute ต้องยาวกว่า :value ตัวอักษร',
    ],
    'gte' => [
        'array' => ':attribute ต้องมีอย่างน้อย :value รายการ',
        'file' => ':attribute ต้องมีขนาดตั้งแต่ :value กิโลไบต์ขึ้นไป',
        'numeric' => ':attribute ต้องมีค่าตั้งแต่ :value ขึ้นไป',
        'string' => ':attribute ต้องมีความยาวตั้งแต่ :value ตัวอักษรขึ้นไป',
    ],
    'hex_color' => ':attribute ต้องเป็นรหัสสีแบบ HEX ที่ถูกต้อง',
    'image' => ':attribute ต้องเป็นไฟล์รูปภาพ',
    'in' => ':attribute มีค่าที่ไม่ถูกต้อง',
    'in_array' => 'ไม่พบค่าของ :attribute ใน :other',
    'in_array_keys' => ':attribute ต้องมีคีย์อย่างน้อยหนึ่งค่าใน :values',
    'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
    'ip' => ':attribute ต้องเป็นหมายเลข IP ที่ถูกต้อง',
    'ipv4' => ':attribute ต้องเป็นหมายเลข IPv4 ที่ถูกต้อง',
    'ipv6' => ':attribute ต้องเป็นหมายเลข IPv6 ที่ถูกต้อง',
    'json' => ':attribute ต้องเป็นข้อความรูปแบบ JSON ที่ถูกต้อง',
    'list' => ':attribute ต้องเป็นรายการแบบเรียงลำดับ',
    'lowercase' => ':attribute ต้องเป็นตัวพิมพ์เล็กทั้งหมด',
    'lt' => [
        'array' => ':attribute ต้องมีน้อยกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดน้อยกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องน้อยกว่า :value',
        'string' => ':attribute ต้องสั้นกว่า :value ตัวอักษร',
    ],
    'lte' => [
        'array' => ':attribute ต้องมีไม่เกิน :value รายการ',
        'file' => ':attribute ต้องมีขนาดไม่เกิน :value กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าไม่เกิน :value',
        'string' => ':attribute ต้องมีความยาวไม่เกิน :value ตัวอักษร',
    ],
    'mac_address' => ':attribute ต้องเป็น MAC address ที่ถูกต้อง',
    'max' => [
        'array' => ':attribute ต้องมีไม่เกิน :max รายการ',
        'file' => ':attribute ต้องมีขนาดไม่เกิน :max กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าไม่เกิน :max',
        'string' => ':attribute ต้องมีความยาวไม่เกิน :max ตัวอักษร',
    ],
    'max_digits' => ':attribute ต้องมีตัวเลขไม่เกิน :max หลัก',
    'mimes' => ':attribute ต้องเป็นไฟล์ชนิด :values',
    'mimetypes' => ':attribute ต้องเป็นไฟล์ชนิด :values',
    'min' => [
        'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'file' => ':attribute ต้องมีขนาดอย่างน้อย :min กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าอย่างน้อย :min',
        'string' => ':attribute ต้องมีความยาวอย่างน้อย :min ตัวอักษร',
    ],
    'min_digits' => ':attribute ต้องมีตัวเลขอย่างน้อย :min หลัก',
    'missing' => 'ต้องไม่ส่ง :attribute มาด้วย',
    'missing_if' => 'ต้องไม่ส่ง :attribute มาด้วย เมื่อ :other คือ :value',
    'missing_unless' => 'ต้องไม่ส่ง :attribute มาด้วย เว้นแต่ :other คือ :value',
    'missing_with' => 'ต้องไม่ส่ง :attribute มาด้วย เมื่อมี :values',
    'missing_with_all' => 'ต้องไม่ส่ง :attribute มาด้วย เมื่อมี :values ครบทุกค่า',
    'multiple_of' => ':attribute ต้องเป็นจำนวนที่หารด้วย :value ลงตัว',
    'not_in' => ':attribute มีค่าที่ไม่ถูกต้อง',
    'not_regex' => ':attribute มีรูปแบบไม่ถูกต้อง',
    'numeric' => ':attribute ต้องเป็นตัวเลข',
    'password' => [
        'letters' => ':attribute ต้องมีตัวอักษรอย่างน้อยหนึ่งตัว',
        'mixed' => ':attribute ต้องมีทั้งตัวพิมพ์ใหญ่และตัวพิมพ์เล็กอย่างละหนึ่งตัว',
        'numbers' => ':attribute ต้องมีตัวเลขอย่างน้อยหนึ่งตัว',
        'symbols' => ':attribute ต้องมีอักขระพิเศษอย่างน้อยหนึ่งตัว',
        /*
         * The one message here that is about the OUTSIDE world: the value was
         * found in a public breach list, not rejected for its shape. Worded
         * so the reader understands it is not their typing that is wrong —
         * "รหัสผ่านนี้เคยหลุด" — because "invalid password" on a password that
         * meets every stated rule reads as a bug in the form.
         */
        'uncompromised' => ':attribute นี้เคยปรากฏในข้อมูลที่รั่วไหล กรุณาใช้รหัสผ่านอื่น',
    ],
    'present' => 'ต้องส่ง :attribute มาด้วย',
    'present_if' => 'ต้องส่ง :attribute มาด้วย เมื่อ :other คือ :value',
    'present_unless' => 'ต้องส่ง :attribute มาด้วย เว้นแต่ :other คือ :value',
    'present_with' => 'ต้องส่ง :attribute มาด้วย เมื่อมี :values',
    'present_with_all' => 'ต้องส่ง :attribute มาด้วย เมื่อมี :values ครบทุกค่า',
    'prohibited' => 'ไม่อนุญาตให้ระบุ :attribute',
    'prohibited_if' => 'ไม่อนุญาตให้ระบุ :attribute เมื่อ :other คือ :value',
    'prohibited_if_accepted' => 'ไม่อนุญาตให้ระบุ :attribute เมื่อยอมรับ :other',
    'prohibited_if_declined' => 'ไม่อนุญาตให้ระบุ :attribute เมื่อปฏิเสธ :other',
    'prohibited_unless' => 'ไม่อนุญาตให้ระบุ :attribute เว้นแต่ :other อยู่ใน :values',
    'prohibits' => ':attribute ทำให้ไม่สามารถระบุ :other ได้',
    'regex' => ':attribute มีรูปแบบไม่ถูกต้อง',
    'required' => 'กรุณากรอก :attribute',
    'required_array_keys' => ':attribute ต้องมีคีย์ :values ครบ',
    'required_if' => 'กรุณากรอก :attribute เมื่อ :other คือ :value',
    'required_if_accepted' => 'กรุณากรอก :attribute เมื่อยอมรับ :other',
    'required_if_declined' => 'กรุณากรอก :attribute เมื่อปฏิเสธ :other',
    'required_unless' => 'กรุณากรอก :attribute เว้นแต่ :other อยู่ใน :values',
    'required_with' => 'กรุณากรอก :attribute เมื่อมี :values',
    'required_with_all' => 'กรุณากรอก :attribute เมื่อมี :values ครบทุกค่า',
    'required_without' => 'กรุณากรอก :attribute เมื่อไม่มี :values',
    'required_without_all' => 'กรุณากรอก :attribute เมื่อไม่มี :values เลย',
    'same' => ':attribute กับ :other ต้องตรงกัน',
    'size' => [
        'array' => ':attribute ต้องมี :size รายการ',
        'file' => ':attribute ต้องมีขนาด :size กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าเท่ากับ :size',
        'string' => ':attribute ต้องมีความยาว :size ตัวอักษร',
    ],
    'starts_with' => ':attribute ต้องขึ้นต้นด้วยค่าใดค่าหนึ่งใน :values',
    'string' => ':attribute ต้องเป็นข้อความ',
    'timezone' => ':attribute ต้องเป็นเขตเวลาที่ถูกต้อง',
    'unique' => ':attribute นี้ถูกใช้ไปแล้ว',
    'uploaded' => 'อัปโหลด :attribute ไม่สำเร็จ ไฟล์อาจมีขนาดใหญ่เกินกำหนดของเซิร์ฟเวอร์',
    'uppercase' => ':attribute ต้องเป็นตัวพิมพ์ใหญ่ทั้งหมด',
    'url' => ':attribute ต้องเป็น URL ที่ถูกต้อง',
    'ulid' => ':attribute ต้องเป็น ULID ที่ถูกต้อง',
    'uuid' => ':attribute ต้องเป็น UUID ที่ถูกต้อง',

    /*
     * Per-field overrides, for the handful of messages where the generic
     * sentence is true and useless.
     *
     * Kept deliberately small. A message belongs here only when the RULE's
     * wording cannot explain the refusal — "password.min" is the example: a
     * reader told "รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร" learns the policy,
     * where the generic string message makes them count. Anything that needs
     * a sentence about this application's own rules belongs in the Form
     * Request that owns it, not in a global file nobody reads before
     * changing a rule.
     */
    'custom' => [
        'password' => [
            'min' => 'รหัสผ่านต้องมีความยาวอย่างน้อย :min ตัวอักษร',
        ],
    ],

    /*
     * Field names, so a refusal names the field the way the screen does.
     *
     * WITHOUT THIS the subject of every sentence is the column name:
     * "กรุณากรอก bank_account_number". The form says "เลขที่บัญชี" above the box
     * and the error underneath it says something else, which reads as an
     * error about a different field.
     *
     * Only the names that appear across MANY forms live here. A field
     * belonging to one screen is named in that screen's own Form Request
     * `attributes()`, next to its rules, where somebody changing the rule can
     * see the label — a global list is one that silently goes stale.
     */
    'attributes' => [
        'email' => 'อีเมล',
        'password' => 'รหัสผ่าน',
        'password_confirmation' => 'ยืนยันรหัสผ่าน',
        'current_password' => 'รหัสผ่านปัจจุบัน',
        'name' => 'ชื่อ',
        'first_name' => 'ชื่อ',
        'last_name' => 'นามสกุล',
        'phone' => 'เบอร์โทรศัพท์',
        'company_id' => 'บริษัท',
        'product_id' => 'สินค้า',
        'product_category_id' => 'หมวดหมู่สินค้า',
        'agent_id' => 'ตัวแทน',
        'client_id' => 'ลูกค้า',
        'manager_id' => 'หัวหน้าทีม',
        'role' => 'สิทธิ์การใช้งาน',
        'rate_type' => 'ชนิดอัตรา',
        'rate_value' => 'อัตรา',
        'effective_from' => 'วันที่เริ่มใช้',
        'effective_to' => 'วันที่สิ้นสุด',
        'date_from' => 'วันที่เริ่มต้น',
        'date_to' => 'วันที่สิ้นสุด',
        'amount_satang' => 'จำนวนเงิน',
        'price_satang' => 'ราคา',
        'bank_name' => 'ธนาคาร',
        'bank_account_number' => 'เลขที่บัญชี',
        'bank_account_holder_name' => 'ชื่อบัญชี',
        'national_id' => 'เลขบัตรประชาชน',
        'file' => 'ไฟล์',
        'avatar' => 'รูปโปรไฟล์',
        'note' => 'หมายเหตุ',
        'status' => 'สถานะ',
    ],
];
