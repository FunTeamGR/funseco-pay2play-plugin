<?php
namespace Funseco\Pay2Play;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\ControllerShutdownRequested;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\KarmaVoteCast;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;
use Funseco\Core\SharedKernel\Events\SocketReady;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\Pay2Play\Pay\ClickHandler;
use Funseco\Pay2Play\Pay\Pay2Play;
use Funseco\Pay2Play\Pay\Pay2PlayWidget;
use Funseco\Pay2Play\VotePay\VotePay;
use Funseco\Pay2Play\VotePay\VotesCommand;

class Pay2PlayServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pay2play.php', 'pay2play');
        $this->publishPluginConfig(__DIR__.'/..');
        $this->app->singleton(Pay2Play::class);
        $this->app->singleton(VotePay::class);

        // The *.enabled flags decide in boot() which listeners exist, so they need a restart; the rest is read lazily.
        $this->app->make(SettingsRegistry::class)->declare('pay2play', 'pay2play', [
            Setting::bool('votepay.enabled', 'Vote pay enabled', restart: true),
            Setting::int('votepay.base', 'Vote pay base', min: 0, unit: 'coppers'),
            Setting::int('votepay.streak3_bonus', 'Vote pay 3-streak bonus', min: 0, unit: 'coppers'),
            Setting::int('votepay.streak5_bonus', 'Vote pay 5-streak bonus', min: 0, unit: 'coppers'),
            Setting::int('votepay.min_server_coppers', 'Vote pay server balance needed', min: 0, unit: 'coppers'),
            Setting::bool('replay.enabled', 'Paid replay enabled', restart: true),
            Setting::int('replay.cost', 'Paid replay cost', min: 0, unit: 'coppers'),
            Setting::int('replay.extra_max', 'Paid replays per track', min: 0),
            Setting::bool('skip.enabled', 'Paid skip enabled', restart: true),
            Setting::int('skip.cost', 'Paid skip cost', min: 0, unit: 'coppers'),
            Setting::int('skip.delay', 'Delay before skip', min: 0, unit: 's'),
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootVotePay();
        $this->bootPay2Play();
    }

    private function bootVotePay(): void
    {
        if (! config('pay2play.votepay.enabled')) {
            return;
        }
        \Chat::register(VotesCommand::class);
        $pay = fn () => app(VotePay::class);
        Event::listen(KarmaVoteCast::class, fn (KarmaVoteCast $e) => $pay()->onVote($e));
        Event::listen(MapStarted::class, fn (MapStarted $e) => $pay()->mapStarted($e));
        Event::listen(MapEnded::class, fn (MapEnded $e) => $pay()->mapEnded($e));
        Event::listen(PlayerConnected::class, fn (PlayerConnected $e) => $pay()->connected($e));
        Event::listen(PlayerDisconnected::class, fn (PlayerDisconnected $e) => $pay()->disconnected($e));
    }

    private function bootPay2Play(): void
    {
        if (! config('pay2play.replay.enabled') && ! config('pay2play.skip.enabled')) {
            return;
        }
        Pay2Play::register(app(WidgetRegistry::class));
        app(ManiaLinkService::class)->addActions([Pay2PlayWidget::REPLAY, Pay2PlayWidget::SKIP], ClickHandler::class);

        $p2p = fn () => app(Pay2Play::class);
        Event::listen(StartupEvent::class, fn () => $p2p()->startup());
        Event::listen(MapStarted::class, fn (MapStarted $e) => $p2p()->mapStarted($e));
        Event::listen(MapEnded::class, fn (MapEnded $e) => $p2p()->mapEnded($e));
        Event::listen(PlayerConnected::class, fn (PlayerConnected $e) => $p2p()->connected($e));
        Event::listen(EverySecondEvent::class, fn () => $p2p()->tick());
        Event::listen(SocketReady::class, fn () => $p2p()->disableCallVotes());
        Event::listen(ControllerShutdownRequested::class, fn () => $p2p()->restoreCallVotes());
    }
}
