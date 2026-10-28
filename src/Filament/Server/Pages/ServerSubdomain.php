<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Filament\Server\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Simplify\Subdomains\Models\Subdomain;
use Simplify\Subdomains\Subdomains;

/**
 * The server's own subdomain. Its owner (or a root admin) picks the name; the records follow after the response.
 *
 * @property Schema $form
 */
final class ServerSubdomain extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'subdomain';

    protected string $view = 'simplify-subdomains::form-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        $user = user();

        return $server instanceof Server
            && $user !== null
            && ((int) $server->owner_id === (int) $user->id || $user->isRootAdmin())
            && Subdomains::ready();
    }

    public static function getNavigationLabel(): string
    {
        return __('simplify-subdomains::subdomains.subdomain');
    }

    public function getTitle(): string
    {
        return __('simplify-subdomains::subdomains.subdomain');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $server = $this->server();
        $name = Subdomains::state($server)['name'];
        $this->form->fill(['name' => $name !== '' ? $name : Subdomains::suggest((string) $server->name, $server->uuid)]);
    }

    /** True while Cloudflare is still being called, so the page keeps checking. */
    public function waiting(): bool
    {
        return in_array(Subdomains::state($this->server())['status'], [Subdomain::PENDING, Subdomain::REMOVING], true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('simplify-subdomains::subdomains.subdomain'))
                ->description(__('simplify-subdomains::subdomains.server.help'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('simplify-subdomains::subdomains.server.name'))
                        ->maxLength(63)
                        ->placeholder('oak')
                        ->dehydrateStateUsing(fn ($state) => strtolower(trim((string) $state)))
                        ->suffix('.'.Subdomains::domain()),
                    Text::make(fn () => $this->status())->visible(fn () => $this->status() !== ''),
                ]),
        ]);
    }

    /** "Connected: oak.example.com", "Not connected: <reason>", or empty when there is no subdomain. */
    public function status(): string
    {
        $state = Subdomains::state($this->server());
        if ($state['name'] === '') {
            return '';
        }
        $label = __('simplify-subdomains::subdomains.status.'.($state['status'] ?: Subdomain::PENDING));
        $detail = $state['status'] === Subdomain::ACTIVE ? (string) Subdomains::address($this->server()) : $state['message'];

        return $detail !== '' ? $label.': '.$detail : $label;
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $name = (string) ($this->form->getState()['name'] ?? '');
        try {
            Subdomains::request($this->server(), $name);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(['data.name' => collect($error->errors())->flatten()->first()]);
        }
        Notification::make()
            ->title(__('simplify-subdomains::subdomains.server.'.($name !== '' ? 'saved' : 'removed')))
            ->success()
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

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
