<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => 'nullable|in:new,running,stopped,completed',
            'strategy_code' => 'nullable',
            'total_investment' => 'nullable',
            'total_profit' => 'nullable',
            'main_level_price' => 'nullable',
            'entry_point_price' => 'nullable',
            'take_profit_price' => 'nullable',
            'take_profit_timeout' => 'nullable',
            'stop_loss_price' => 'nullable',
            'trailing_delta' => 'nullable',
            'stop_loss_safe_time' => 'nullable'
        ];
    }
}
