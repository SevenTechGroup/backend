<?php

namespace App\Http\Requests;

use App\Enums\ReportPriority;
use App\Models\Report;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Report::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:20', 'max:1000'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn (Builder $query) => $query->where('is_active', true),
                ),
            ],
            'territory_id' => [
                'required',
                'integer',
                Rule::exists('territories', 'id')->where(
                    fn (Builder $query) => $query->where('is_active', true),
                ),
            ],
            'location_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'priority' => ['sometimes', 'required', Rule::enum(ReportPriority::class)],
        ];
    }
}
