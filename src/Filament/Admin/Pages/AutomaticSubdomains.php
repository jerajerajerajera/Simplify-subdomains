<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Filament\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Simplify\Subdomains\Services\CloudflareDns;
use Simplify\Subdomains\Services\Settings;
use Simplify\Subdomains\Subdomains;

/**
 * Where a root admin sets the domain, the game address, the forwarded ports and the Cloudflare token.
 *
 * @property Schema $form
 */
final class AutomaticSubdomains extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'simplify-subdomains';

    protected string $view = 'simplify-subdomains::form-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) user()?->isRootAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return __('simplify-subdomains::subdomains.title');
    }

    /** In the Simplify group of Pelican's menu, with the other Simplify add-ons (Simplify's ADDONS.md, "Admin menu"). */
    public static function getNavigationGroup(): ?string
    {
        return 'Simplify';
    }

    public function getTitle(): string
    {
        return __('simplify-subdomains::subdomains.title');
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function waiting(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('simplify-subdomains::subdomains.settings.addresses'))
                ->description(__('simplify-subdomains::subdomains.settings.addresses_help'))
                ->columns(2)
                ->schema([
                    self::hostInput('domain', 'example.com', 200)
                        ->label(__('simplify-subdomains::subdomains.settings.domain'))
                        ->helperText(__('simplify-subdomains::subdomains.settings.domain_help')),
                    self::hostInput('target', 'play.example.com', 253)
                        ->label(__('simplify-subdomains::subdomains.settings.target'))
                        ->helperText(__('simplify-subdomains::subdomains.settings.target_help')),
                    TextInput::make('port_min')
                        ->label(__('simplify-subdomains::subdomains.settings.port_min'))
                        ->integer()->minValue(1)->maxValue(65535)->required(),
                    TextInput::make('port_max')
                        ->label(__('simplify-subdomains::subdomains.settings.port_max'))
                        ->integer()->minValue(1)->maxValue(65535)->required()->gte('port_min')
                        ->helperText(__('simplify-subdomains::subdomains.settings.ports_help')),
                ]),
            Section::make(__('simplify-subdomains::subdomains.settings.cloudflare_token'))
                ->description(__('simplify-subdomains::subdomains.form.token_help'))
                ->columns(2)
                ->schema([
                    TextInput::make('token')
                        ->label(__('simplify-subdomains::subdomains.form.api_token'))
                        ->password()
                        ->autocomplete(false)
                        ->maxLength(200)
                        ->columnSpanFull()
                        ->placeholder(fn () => Settings::all()['has_token']
                            ? __('simplify-subdomains::subdomains.form.token_saved')
                            : __('simplify-subdomains::subdomains.form.token_paste')),
                    TextInput::make('zone_id')
                        ->label(__('simplify-subdomains::subdomains.form.zone_id'))
                        ->rules(['nullable', 'regex:'.Settings::ZONE])
                        ->maxLength(32)
                        ->placeholder(__('simplify-subdomains::subdomains.form.zone_placeholder'))
                        ->helperText(__('simplify-subdomains::subdomains.form.zone_help')),
                    Toggle::make('remove_token')
                        ->label(__('simplify-subdomains::subdomains.form.remove_token'))
                        ->visible(fn () => Settings::all()['has_token']),
                    Actions::make([
                        Action::make('test')
                            ->label(__('simplify-subdomains::subdomains.form.test'))
                            ->icon('tabler-plug-connected')
                            ->color('gray')
                            ->action(fn () => $this->test()),
                    ])->columnSpanFull(),
                    Text::make(fn () => $this->readiness())->columnSpanFull(),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        Settings::save($state, ! empty($state['remove_token']));
        $this->fillForm();
        Notification::make()->title(__('simplify-subdomains::subdomains.saved'))->success()->send();
    }

    /** Checks the typed (or saved) token and fills in the zone ID of the domain. */
    public function test(): void
    {
        abort_unless(self::canAccess(), 403);
        $token = trim((string) data_get($this->data, 'token', ''));
        if ($token === '') {
            [$token] = Settings::credentials();
        }
        $domain = strtolower(trim((string) data_get($this->data, 'domain', '')));
        $result = match (true) {
            $token === '' => ['ok' => false, 'message' => __('simplify-subdomains::subdomains.test.token_first'), 'zone_id' => null],
            $domain === '' => ['ok' => false, 'message' => __('simplify-subdomains::subdomains.test.domain_first'), 'zone_id' => null],
            default => CloudflareDns::findZone(Subdomains::$transport ?? CloudflareDns::http($token), $domain),
        };
        if ($result['zone_id'] !== null) {
            data_set($this->data, 'zone_id', $result['zone_id']);
        }
        Notification::make()
            ->title(__('simplify-subdomains::subdomains.test.'.($result['ok'] ? 'title_ok' : 'title_failed')))
            ->body($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->hiddenLabel()
                ->icon('tabler-device-floppy')
                ->tooltip(__('simplify-subdomains::subdomains.save'))
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    private static function hostInput(string $name, string $placeholder, int $length): TextInput
    {
        return TextInput::make($name)
            ->rules(['nullable', 'regex:'.Settings::HOST])
            ->validationMessages(['regex' => __('simplify-subdomains::subdomains.settings.host_name')])
            ->dehydrateStateUsing(fn ($state) => strtolower(trim((string) $state)))
            ->placeholder($placeholder)
            ->maxLength($length);
    }

    private function fillForm(): void
    {
        $this->form->fill([...Settings::all(), 'token' => '', 'remove_token' => false]);
    }

    private function readiness(): string
    {
        $missing = Settings::missing();
        if ($missing === []) {
            return __('simplify-subdomains::subdomains.settings.ready');
        }
        $items = array_map(fn (string $item) => __('simplify-subdomains::subdomains.missing.'.$item), $missing);

        return __('simplify-subdomains::subdomains.settings.still_missing', ['items' => implode(', ', $items)]);
    }
}
