<?php

namespace App\Http\Requests;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof Assignment
            && ($this->user()?->can('update', $assignment) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::enum(AssignmentStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['status', 'notes'])) {
                    $validator->errors()->add('request', 'At least one field must be provided.');

                    return;
                }

                $assignment = $this->route('assignment');
                $nextStatus = AssignmentStatus::tryFrom((string) $this->input('status'));

                if ($assignment instanceof Assignment
                    && $nextStatus !== null
                    && ! $assignment->canTransitionTo($nextStatus)) {
                    $validator->errors()->add('status', 'This status transition is not allowed.');
                }
            },
        ];
    }
}
