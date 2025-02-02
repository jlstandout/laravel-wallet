<?php

namespace Bavix\Wallet\Commands;

use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Services\ProxyService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use function config;

/**
 * Class RefreshBalance
 * @package Bavix\Wallet\Commands
 * @codeCoverageIgnore
 */
class RefreshBalance extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculates all wallets';

    /**
     * @return void
     */
    public function handle(): void
    {
        app(ProxyService::class)->fresh();
        DB::transaction(function () {
            $wallet = config('wallet.wallet.table');
            DB::table($wallet)->update(['balance' => 0]);

            if (DB::connection() instanceof SQLiteConnection) {
                $this->sqliteUpdate();
            } else {
                $this->multiUpdate();
            }
        });
    }

    /**
     * SQLite
     *
     * @return void
     */
    protected function sqliteUpdate(): void
    {
        Wallet::query()->each(static function (Wallet $wallet) {
            $wallet->refreshBalance();
        });
    }

    /**
     * MySQL/PgSQL
     *
     * @return void
     */
    protected function multiUpdate(): void
    {
        $wallet = config('wallet.wallet.table');
        $trans = config('wallet.transaction.table');
        $availableBalance = DB::table($trans)
            ->select('wallet_id', DB::raw('sum(amount) balance'))
            ->where('confirmed', true)
            ->groupBy('wallet_id');

        $joinClause = static function (JoinClause $join) use ($wallet) {
            $join->on("$wallet.id", '=', 'b.wallet_id');
        };

        DB::table($wallet)
            ->joinSub($availableBalance, 'b', $joinClause, null, null, 'left')
            ->update(['balance' => DB::raw('b.balance')]);
    }

}
