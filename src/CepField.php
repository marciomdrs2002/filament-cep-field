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
        $this->minLength(9);

        $this->suffixAction(
            Action::make('search_cep')
                ->icon('heroicon-m-magnifying-glass')
                ->action(function ($state, Set $set): void {
                    if (blank($state)) {
                        return;
                    }

                    try {
                        $data = CepService::find($state);

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
                })
        );
    }
}
