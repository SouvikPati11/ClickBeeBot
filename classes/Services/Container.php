<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Models\CampaignRepository;
use App\Models\SubmissionRepository;
use App\Models\UserRepository;
use App\Tasks\TaskRegistry;
use App\Telegram\TelegramApi;

/**
 * Bot-level service factory.
 *
 * Builds and memoises the repositories, services, task registry and Telegram
 * client on top of the core kernel. Shared by the webhook and every cron job
 * so wiring lives in exactly one place.
 */
final class Container
{
    private array $singletons = [];

    public function __construct(private App $app)
    {
    }

    public function app(): App
    {
        return $this->app;
    }

    public function telegram(): TelegramApi
    {
        return $this->singletons[__FUNCTION__] ??= new TelegramApi(
            $this->app->settings()->get('bot_token', ''),
            $this->app->logger()
        );
    }

    public function users(): UserRepository
    {
        return $this->singletons[__FUNCTION__] ??= new UserRepository($this->app->db());
    }

    public function campaigns(): CampaignRepository
    {
        return $this->singletons[__FUNCTION__] ??= new CampaignRepository($this->app->db());
    }

    public function submissions(): SubmissionRepository
    {
        return $this->singletons[__FUNCTION__] ??= new SubmissionRepository($this->app->db());
    }

    public function ledger(): Ledger
    {
        return $this->singletons[__FUNCTION__] ??= new Ledger($this->app->db());
    }

    public function state(): StateManager
    {
        return $this->singletons[__FUNCTION__] ??= new StateManager($this->app->db());
    }

    public function notifications(): NotificationService
    {
        return $this->singletons[__FUNCTION__] ??= new NotificationService(
            $this->app->db(),
            $this->users(),
            $this->telegram()
        );
    }

    public function referrals(): ReferralService
    {
        return $this->singletons[__FUNCTION__] ??= new ReferralService(
            $this->app->db(),
            $this->app->settings(),
            $this->users(),
            $this->ledger()
        );
    }

    public function registry(): TaskRegistry
    {
        return $this->singletons[__FUNCTION__] ??= new TaskRegistry($this->app->db(), $this->telegram());
    }

    public function campaignService(): CampaignService
    {
        return $this->singletons[__FUNCTION__] ??= new CampaignService($this->app->db(), $this->registry());
    }

    public function withdrawService(): WithdrawService
    {
        return $this->singletons[__FUNCTION__] ??= new WithdrawService(
            $this->app->db(),
            $this->app->settings(),
            $this->ledger()
        );
    }

    public function oxapay(): OxapayGateway
    {
        return $this->singletons[__FUNCTION__] ??= new OxapayGateway(
            $this->app->settings(),
            $this->app->logger(),
            $this->app->config()->baseUrl()
        );
    }

    public function depositService(): DepositService
    {
        return $this->singletons[__FUNCTION__] ??= new DepositService(
            $this->app->db(),
            $this->app->settings(),
            $this->oxapay(),
            $this->ledger(),
            $this->referrals()
        );
    }

    public function taskEngine(): TaskEngine
    {
        return $this->singletons[__FUNCTION__] ??= new TaskEngine(
            $this->telegram(),
            $this->registry(),
            $this->campaigns(),
            $this->submissions(),
            $this->users(),
            $this->ledger(),
            $this->notifications(),
            $this->state(),
            $this->referrals()
        );
    }
}
