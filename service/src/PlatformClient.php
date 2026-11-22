<?php
declare(strict_types=1);

namespace DailyPass;

/**
 * HTTP client for the Carrier Platform (§A–§D of the contract).
 * The password is attached here and nowhere else; it never appears in logs.
 */
final class PlatformClient
{
    /** @param array<string, mixed> $cfg */
    public function __construct(private readonly array $cfg)
    {
    }

    /** @return array<string, mixed> */
    private function authFields(): array
    {
        return ['applicationId' => $this->cfg['applicationId'], 'password' => $this->cfg['password'], 'version' => '1.0'];
    }

    /**
     * §A — opt-in ("1") / opt-out ("0").
     * @return array<string, mixed>
     */
    public function subscriptionSend(string $subscriberId, string $action): array
    {
        return $this->post('/subscription/send', [...$this->authFields(), 'action' => $action, 'subscriberId' => $subscriberId]);
    }

    /** @return array<string, mixed> */
    public function smsSend(string $destinationAddress, string $message): array
    {
        return $this->post('/sms/send', [
            ...$this->authFields(),
            'message' => $message,
            'destinationAddresses' => [$destinationAddress],
            'sourceAddress' => $this->cfg['shortcode'],
            'encoding' => '0',
        ]);
    }

    /** @return array<string, mixed> */
    public function directDebit(string $externalTrxId, string $subscriberId): array
    {
        return $this->post('/caas/direct/debit', [
            ...$this->authFields(),
            'externalTrxId' => $externalTrxId,
            'subscriberId' => $subscriberId,
            'paymentInstrumentName' => 'Mobile Account',
            'amount' => $this->cfg['amount'],
            'currency' => $this->cfg['currency'],
        ]);
    }

    /**
     * Demo-only reconciliation read (§D note): look up the original result of
     * an already-processed externalTrxId (a real gateway would expose a query
     * API; the mock provides /admin/debits/:key). Never throws.
     *
     * @return array<string, mixed>|null
     */
    public function reconcileDebit(string $externalTrxId): ?array
    {
        try {
            $res = Http::getJson($this->cfg['platformUrl'] . '/admin/debits/' . rawurlencode($externalTrxId), $this->cfg['httpTimeoutMs'], false);
            if ($res['status'] === 200 && ($res['data']['statusCode'] ?? null) === 'S1000') {
                return $res['data'];
            }
            return null;
        } catch (\Throwable $e) {
            Logger::warn('reconcile query failed', ['externalTrxId' => $externalTrxId, 'error' => (string)$e]);
            return null;
        }
    }

    /** @return array<string, mixed> @throws \RuntimeException on transport failure */
    private function post(string $path, array $payload): array
    {
        return Http::postJson($this->cfg['platformUrl'] . $path, $payload, $this->cfg['httpTimeoutMs'], true);
    }
}
