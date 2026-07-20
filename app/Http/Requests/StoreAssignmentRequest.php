<?php

namespace App\Http\Requests;

use App\Enums\AssignmentStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Assignment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'report_id' => [
                'required',
                'integer',
                'exists:reports,id',
                Rule::unique('assignments', 'report_id')->where(
                    fn (Builder $query) => $query->whereIn('status', [
                        AssignmentStatus::Assigned->value,
                        AssignmentStatus::InProgress->value,
                    ]),
                ),
            ],
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn (Builder $query) => $query->where('role', UserRole::Agent->value),
                ),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
