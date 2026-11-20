<?php

namespace App\Http\Requests\Client;

use App\Services\Scheduling\MeetingBookings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The days to show open meeting times for: from today on, at most a month at a time.
 */
class MeetingSlotsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now(config('app.display_timezone'))->toDateString();

        return [
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = CarbonImmutable::parse((string) $this->input('from'));
                $to = CarbonImmutable::parse((string) $this->input('to'));

                if ($from->diffInDays($to) >= MeetingBookings::MAX_DAYS) {
                    $validator->errors()->add('to', 'Ask for at most '.MeetingBookings::MAX_DAYS.' days of times at once.');
                }
            },
        ];
    }
}
