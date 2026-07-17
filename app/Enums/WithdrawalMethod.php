<?php

namespace App\Enums;

enum WithdrawalMethod: string
{
    case BankAccount = 'bank_account';
    case Transfer = 'transfer';
}
