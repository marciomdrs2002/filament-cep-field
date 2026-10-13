<?php

namespace Marciomdrs\FilamentCepField;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Throwable;

class CepField extends TextInput
{
    protected string $streetField = 'address';

    protected ?string $neighborhoodField = null;

    protected string $cityField = 'city';

    protected string $stateField = 'state';

    /**
     * Map the form fields the lookup should populate. Pass a `neighborhood` field name to
     * also fill the bairro (Asaas calls it `province`).
     */
    public function addressFields(string $street = 'address', ?string $neighborhood = null, string $city = 'city', string $state = 'state'): static
    {
        $this->streetField = $street;
        $this->neighborhoodField = $neighborhood;
        $this->cityField = $city;
        $this->stateField = $state;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mask('99999-999');
        $this->placeholder('59000-000');

        // The mask is presentation only. Stripping it keeps the stored CEP as digits, so the
        // value has a single representation for lookups, comparisons and for APIs that reject
        // formatted input. Filament registers a state cast for this, so the state that gets
        // validated and dehydrated is already unmasked.
        $this->stripCharacters(['-']);

        // Length is checked against that unmasked state: 8 digits, not the 9 characters the
        // mask renders. A rule, not minLength()/maxLength(): those also render as HTML
        // minlength/maxlength, which the browser counts against the masked text, so
        // maxlength="8" stopped typing at "59000-00" and no complete CEP could be entered.
        $this->rule('digits:8');

        // Lookup runs on its own once the typing pauses on a complete CEP, and the
        // magnifier stays for retrying after a failure. Partial input is ignored so the
        // debounce does not hit the APIs or flash "not found" mid-typing.
        $this->live(debounce: 600);
        $this->afterStateUpdated(function ($state, Set $set): void {
            if (strlen(preg_replace('/\D/', '', (string) $state)) === 8) {
                $this->fillAddress($state, $set);
            }
        });

        $this->suffixAction(
            Action::make('search_cep')
                ->icon('heroicon-m-magnifying-glass')
                ->action(function ($state, Set $set): void {
                    if (blank($state)) {
                        return;
                    }

                    $this->fillAddress($state, $set);
                })
        );
    }

    protected function fillAddress(?string $cep, Set $set): void
    {
        try {
            $data = CepService::find($cep);

            if ($data) {
                $set($this->streetField, $data['street']);

                if ($this->neighborhoodField !== null) {
                    $set($this->neighborhoodField, $data['neighborhood'] ?? null);
                }

                $set($this->cityField, $data['city']);
                $set($this->stateField, $data['state']);

                return;
            }

            $set($this->streetField, null);

            if ($this->neighborhoodField !== null) {
                $set($this->neighborhoodField, null);
            }

            $set($this->cityField, null);
            $set($this->stateField, null);

            Notification::make()
                ->title('CEP não encontrado')
                ->body('Verifique se o CEP está correto ou tente novamente mais tarde.')
                ->danger()
                ->send();
        } catch (Throwable) {
            Notification::make()
                ->title('Erro ao consultar CEP')
                ->body('Ocorreu um problema ao consultar o CEP. Tente novamente em alguns instantes.')
                ->danger()
                ->send();
        }
    }
}
