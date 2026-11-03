<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ServiceContractTerms
{
    public function validate(int $actor, array $input): array
    {
        $decimal = ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'];
        $quantity = ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/D'];
        $d = Validator::make($input, [
            'branch_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1',
            'responsible_employee_id' => 'required|integer|min:1', 'title' => 'required|string|min:3|max:200|not_regex:/\x00/',
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'value' => $decimal, 'service_product_id' => 'required|integer|min:1', 'billing_mode' => 'required|in:installments,measured_work',
            'pricing_tax_mode' => 'nullable|in:inclusive,exclusive',
            'terms' => 'required|string|max:20000|not_regex:/\x00/',
            'sla_response_minutes' => 'required|integer|min:1|max:525600', 'sla_resolution_minutes' => 'required|integer|min:1|max:525600|gte:sla_response_minutes',
            'covered_labor_minutes' => 'required|integer|min:0|max:5256000',
            'coverage' => 'required|array|list|min:1|max:500', 'coverage.*' => 'array:equipment_ref,site_id,preventive_visits,corrective_visits',
            'coverage.*.equipment_ref' => 'required|string|max:160|not_regex:/\x00/', 'coverage.*.site_id' => 'nullable|integer|min:1',
            'coverage.*.preventive_visits' => 'required|integer|min:0|max:100000', 'coverage.*.corrective_visits' => 'required|integer|min:0|max:100000',
            'preventive_schedule' => 'sometimes|array|list|max:500', 'preventive_schedule.*' => 'array:code,equipment_ref,site_id,due_date,description',
            'preventive_schedule.*.code' => 'required|string|max:50|distinct', 'preventive_schedule.*.equipment_ref' => 'required|string|max:160',
            'preventive_schedule.*.site_id' => 'nullable|integer|min:1', 'preventive_schedule.*.due_date' => 'required|date_format:Y-m-d|after_or_equal:starts_on|before_or_equal:ends_on',
            'preventive_schedule.*.description' => 'required|string|min:5|max:2000',
            'parts' => 'present|array|list|max:500', 'parts.*' => 'array:product_id,quantity', 'parts.*.product_id' => 'required|integer|min:1|distinct', 'parts.*.quantity' => $quantity,
            'excluded_parts' => 'present|array|list|max:500', 'excluded_parts.*' => 'required|integer|min:1|distinct',
            'schedule' => 'present|array|list|max:500', 'schedule.*' => 'array:code,due_date,amount,description',
            'schedule.*.code' => 'required|string|max:50|distinct', 'schedule.*.due_date' => 'required|date_format:Y-m-d|after_or_equal:starts_on|before_or_equal:ends_on',
            'schedule.*.amount' => $decimal, 'schedule.*.description' => 'required|string|max:500',
            'boq' => 'present|array|list|max:500', 'boq.*' => 'array:code,description,product_id,quantity,rate', 'boq.*.code' => 'required|string|max:50|distinct',
            'boq.*.description' => 'required|string|max:500', 'boq.*.product_id' => 'required|integer|min:1', 'boq.*.quantity' => $quantity, 'boq.*.rate' => $decimal,
        ])->validate();
        $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $d['customer_id']));
        $employee = app(OrganizationApi::class)->employee($actor, (int) $d['responsible_employee_id'], (int) $d['branch_id'], $d['starts_on']);
        $d['customer_name'] = $customer->name;
        $d['responsible_employee_name'] = $employee['name'];
        $service = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $d['service_product_id']))->product;
        if (! ($service['active'] ?? false) || ($service['kind'] ?? null) !== 'service') {
            throw ValidationException::withMessages(['service_product_id' => 'يلزم صنف خدمة نشط حقيقي.']);
        }
        $seen = [];
        foreach ($d['coverage'] as &$coverage) {
            $coverage['equipment_ref'] = mb_strtoupper(trim($coverage['equipment_ref']));
            $coverage['site_id'] = empty($coverage['site_id']) ? null : (int) $coverage['site_id'];
            $key = $coverage['equipment_ref'].'|'.($coverage['site_id'] ?? '');
            if ($coverage['equipment_ref'] === '' || isset($seen[$key])) {
                throw ValidationException::withMessages(['coverage' => 'المعدة والموقع يجب أن يكونا محددين دون تكرار.']);
            }
            $seen[$key] = true;
            app(CustomersApi::class)->maintenanceContext($actor, (int) $d['customer_id'], $coverage['site_id']);
        }
        unset($coverage);
        $planned = [];
        $schedule = $d['preventive_schedule'] ?? [];
        foreach ($schedule as &$visit) {
            $visit['equipment_ref'] = mb_strtoupper(trim($visit['equipment_ref']));
            $visit['site_id'] = empty($visit['site_id']) ? null : (int) $visit['site_id'];
            $coverage = collect($d['coverage'])->first(fn ($row) => $row['equipment_ref'] === $visit['equipment_ref'] && $row['site_id'] === $visit['site_id']);
            $key = $visit['equipment_ref'].'|'.($visit['site_id'] ?? '');
            $planned[$key] = ($planned[$key] ?? 0) + 1;
            if (! $coverage || $planned[$key] > (int) $coverage['preventive_visits']) {
                throw ValidationException::withMessages(['preventive_schedule' => 'المواعيد الوقائية يجب أن تطابق المعدة والموقع وعدد الزيارات المغطاة.']);
            }
        }
        unset($visit);
        if (array_key_exists('preventive_schedule', $d)) {
            $d['preventive_schedule'] = $schedule;
        }
        foreach ($d['parts'] as &$part) {
            $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $part['product_id']))->product;
            if (! ($p['active'] ?? false) || ($p['kind'] ?? null) === 'service' || bccomp($part['quantity'], '0', 8) <= 0 || in_array($part['product_id'], $d['excluded_parts'])) {
                throw ValidationException::withMessages(['parts' => 'قطع التغطية موجبة ونشطة وغير مستثناة.']);
            }
            $part['quantity'] = bcadd($part['quantity'], '0', 8);
        }
        unset($part);
        foreach ($d['excluded_parts'] as $id) {
            app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $id));
        }
        $total = '0.00';
        if ($d['billing_mode'] === 'installments') {
            if ($d['schedule'] === [] || $d['boq'] !== []) {
                throw ValidationException::withMessages(['billing_mode' => 'جدول أقساط حصري مطلوب.']);
            }
            foreach ($d['schedule'] as &$line) {
                if (bccomp($line['amount'], '0', 2) <= 0) {
                    throw ValidationException::withMessages(['schedule' => 'القسط يجب أن يكون موجباً.']);
                }
                $line['amount'] = bcadd($line['amount'], '0', 2);
                $total = bcadd($total, $line['amount'], 2);
            }
            unset($line);
        } else {
            if ($d['boq'] === [] || $d['schedule'] !== []) {
                throw ValidationException::withMessages(['billing_mode' => 'جدول كميات حصري مطلوب.']);
            }
            foreach ($d['boq'] as &$line) {
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $line['product_id']))->product;
                if (! ($p['active'] ?? false) || ($p['kind'] ?? null) !== 'service' || bccomp($line['quantity'], '0', 8) <= 0 || bccomp($line['rate'], '0', 2) <= 0) {
                    throw ValidationException::withMessages(['boq' => 'كمية وسعر موجبان لصنف خدمة نشط مطلوبان.']);
                }
                $line['quantity'] = bcadd($line['quantity'], '0', 8);
                $line['rate'] = bcadd($line['rate'], '0', 2);
                $total = bcadd($total, bcdiv(bcadd(bcmul($line['quantity'], $line['rate'], 10), '0.005', 10), '1', 2), 2);
            }
            unset($line);
        }
        if (bccomp($d['value'], '0', 2) <= 0 || bccomp($d['value'], $total, 2) !== 0) {
            throw ValidationException::withMessages(['value' => 'يجب أن تطابق قيمة العقد مجموع الجدول المعتمد.']);
        }
        $d['value'] = bcadd($d['value'], '0', 2);

        return $d;
    }
}
