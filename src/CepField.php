<?php

namespace Marciomdrs\FilamentCepField;

use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

class CepField extends TextInput
{
    protected string $streetField = 'address';

    protected ?string $neighborhoodField = null;

    protected string $cityField = 'city';

    protected string $stateField = 'state';

    protected ?string $focusAfterFillField = null;

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

    /**
     * Move the cursor to this field (usually the street number, the only part the lookup
     * cannot know) once the address is filled. It must sit at the same level of the form
     * data as the CEP field.
     */
    public function focusAfterFill(?string $field): static
    {
        $this->focusAfterFillField = $field;

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

        // Lookup runs on its own once the typing pauses on a complete CEP; retrying after a
        // failure is editing the CEP again. Partial input is ignored so the debounce does not
        // hit the APIs or flash "not found" mid-typing.
        $this->live(debounce: 600);
        $this->afterStateUpdated(function ($state, Set $set, Component $livewire): void {
            if (strlen(preg_replace('/\D/', '', (string) $state)) === 8) {
                $this->fillAddress($state, $set, $livewire);
            }
        });

        // Shown only while the update that runs the lookup is in flight; the delay keeps it
        // from flashing on the quick round-trips of partial input.
        $this->suffix(
            fn (CepField $component): HtmlString => new HtmlString(Blade::render(
                '<x-filament::loading-indicator wire:loading.delay.default wire:target="{{ $target }}" class="h-5 w-5 text-gray-400 dark:text-gray-500" />',
                ['target' => $component->getStatePath()],
            )),
            isInline: true,
        );
    }

    protected function fillAddress(?string $cep, Set $set, Component $livewire): void
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

                $this->focusNextField($livewire);

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

    /**
     * The target's input id is this field's id with the last key swapped (e.g. `form.zip_code`
     * becomes `form.address_number`); the id follows the schema keys, not the state path. The
     * focus runs after the response is applied to the page, so the field is already filled.
     */
    protected function focusNextField(Component $livewire): void
    {
        if ($this->focusAfterFillField === null) {
            return;
        }

        $id = $this->getId();
        $target = Str::contains($id, '.')
            ? Str::beforeLast($id, '.').'.'.$this->focusAfterFillField
            : $this->focusAfterFillField;

        $livewire->js('setTimeout(() => document.getElementById('.json_encode($target).')?.focus())');
    }
}
