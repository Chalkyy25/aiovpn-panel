<?php

namespace App\Filament\Reseller\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Credits extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Credits';

    protected static string $view = 'filament.reseller.pages.credits';

    public function getBalanceProperty(): int
    {
        return (int) (auth()->user()?->fresh()?->credits ?? 0);
    }

    protected function getHeaderActions(): array
    {
        $supportEmail = (string) (config('mail.from.address') ?? '');
        $mailto = $supportEmail !== ''
            ? ('mailto:' . $supportEmail . '?subject=' . rawurlencode('Credits top-up request'))
            : null;

        $action = Action::make('purchase')
            ->label('Purchase more')
            ->icon('heroicon-o-shopping-cart')
            ->modalHeading('Purchase credits')
            ->requiresConfirmation()
            ->modalDescription(
                $mailto
                    ? 'This will open your email client to request a credits top-up.'
                    : 'Please contact support/admin to top up your credits.'
            );

        if ($mailto) {
            $action = $action
                ->url($mailto)
                ->openUrlInNewTab();
        } else {
            // No configured email: still show the modal so the user has an obvious next step.
            $action = $action->action(fn () => null);
        }

        return [$action];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('change')
                    ->label('Change')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => ((int) $state) > 0 ? ('+' . (int) $state) : (string) (int) $state)
                    ->color(fn ($state) => ((int) $state) >= 0 ? 'success' : 'danger')
                    ->sortable(),
            ])
            ->actions([])
            ->bulkActions([])
            ->paginated([20, 50, 100]);
    }

    protected function getTableQuery(): Builder
    {
        $user = auth()->user();

        return $user
            ? $user->creditTransactions()->getQuery()
            : \App\Models\CreditTransaction::query()->whereRaw('1=0');
    }
}
