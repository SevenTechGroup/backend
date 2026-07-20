<?php

namespace App\Http\Requests;

use App\Enums\ReportPriority;
use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return $report instanceof Report
            && ($this->user()?->can('update', $report) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::enum(ReportStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(ReportPriority::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['status', 'priority'])) {
                    $validator->errors()->add('request', 'At least one field must be provided.');

                    return;
                }

                $report = $this->route('report');
                $nextStatus = ReportStatus::tryFrom((string) $this->input('status'));

                if ($report instanceof Report
                    && $nextStatus !== null
                    && ! $report->canTransitionTo($nextStatus)) {
                    $validator->errors()->add('status', 'This status transition is not allowed.');
                }
            },
        ];
    }
}
