<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\ReportPriority;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProductionDemoSeeder extends Seeder
{
    private const ACCOUNT_ROLES = [
        'manager' => UserRole::Manager,
        'intervenant' => UserRole::Agent,
        'citizen_1' => UserRole::Citizen,
        'citizen_2' => UserRole::Citizen,
        'citizen_3' => UserRole::Citizen,
    ];

    public function run(): void
    {
        if (! app()->environment('production', 'testing')) {
            throw new LogicException('Les comptes de démonstration de production sont réservés aux environnements production et testing.');
        }

        $accounts = $this->validatedAccounts();

        $this->call(ProductionDataSeeder::class);

        DB::transaction(function () use ($accounts): void {
            $users = collect($accounts)->mapWithKeys(function (array $account, string $key): array {
                $user = User::query()->updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => $account['password'],
                        'role' => self::ACCOUNT_ROLES[$key]->value,
                        'email_verified_at' => now(),
                    ],
                );

                return [$key => $user];
            });

            $categories = Category::query()->get()->keyBy('slug');
            $territories = Territory::query()->get()->keyBy('code');

            $reports = [
                [
                    'citizen' => 'citizen_1',
                    'title' => 'Dépôt sauvage près du marché Sandaga',
                    'description' => 'Un important dépôt de déchets bloque le passage des piétons depuis plusieurs jours près de l’entrée du marché.',
                    'category' => 'dechets',
                    'territory' => 'DKR',
                    'location_text' => 'Entrée sud du marché Sandaga',
                    'priority' => ReportPriority::High->value,
                    'status' => ReportStatus::Received->value,
                ],
                [
                    'citizen' => 'citizen_2',
                    'title' => 'Lampadaires éteints dans la rue principale',
                    'description' => 'Quatre lampadaires ne fonctionnent plus et toute la rue reste sombre après 20 heures.',
                    'category' => 'eclairage-public',
                    'territory' => 'GWD',
                    'location_text' => 'Rue principale, derrière la mairie',
                    'priority' => ReportPriority::Medium->value,
                    'status' => ReportStatus::InProgress->value,
                ],
                [
                    'citizen' => 'citizen_3',
                    'title' => 'Nid-de-poule dangereux sur la chaussée',
                    'description' => 'Un trou profond occupe une partie de la voie et oblige les véhicules à effectuer un écart dangereux.',
                    'category' => 'voirie',
                    'territory' => 'PKN',
                    'location_text' => 'Route de Pikine, près du terminus',
                    'priority' => ReportPriority::High->value,
                    'status' => ReportStatus::Resolved->value,
                ],
                [
                    'citizen' => 'citizen_1',
                    'title' => 'Canal d’évacuation bouché après les pluies',
                    'description' => 'Le canal ne laisse plus passer l’eau et plusieurs habitations commencent à être touchées par la montée des eaux.',
                    'category' => 'eau-assainissement',
                    'territory' => 'DKR',
                    'location_text' => 'Quartier Grand-Yoff, zone de captage',
                    'priority' => ReportPriority::High->value,
                    'status' => ReportStatus::InProgress->value,
                ],
                [
                    'citizen' => 'citizen_2',
                    'title' => 'Animal blessé sur la voie publique',
                    'description' => 'Un animal blessé est immobilisé au bord de la route et nécessite une prise en charge rapide.',
                    'category' => 'sante-animale',
                    'territory' => 'PKN',
                    'location_text' => 'À proximité du rond-point Tally Bou Mack',
                    'priority' => ReportPriority::Medium->value,
                    'status' => ReportStatus::Received->value,
                ],
                [
                    'citizen' => 'citizen_3',
                    'title' => 'Bac de collecte remplacé avec succès',
                    'description' => 'Le bac endommagé signalé la semaine dernière a été remplacé et la zone a été entièrement nettoyée.',
                    'category' => 'dechets',
                    'territory' => 'GWD',
                    'location_text' => 'Quartier Hamo 4, près de l’école',
                    'priority' => ReportPriority::Low->value,
                    'status' => ReportStatus::Resolved->value,
                ],
            ];

            $persistedReports = collect($reports)->map(function (array $data) use ($categories, $territories, $users): Report {
                $category = $categories->get($data['category']);
                $territory = $territories->get($data['territory']);
                $citizen = $users->get($data['citizen']);

                if (! $category || ! $territory || ! $citizen) {
                    throw new LogicException('Les comptes et référentiels doivent être créés avant les signalements.');
                }

                return Report::query()->updateOrCreate(
                    ['user_id' => $citizen->id, 'title' => $data['title']],
                    [
                        'category_id' => $category->id,
                        'territory_id' => $territory->id,
                        'description' => $data['description'],
                        'location_text' => $data['location_text'],
                        'priority' => $data['priority'],
                        'status' => $data['status'],
                    ],
                );
            })->values();

            $assignmentData = [
                [0, AssignmentStatus::Assigned->value, 'Vérifier la zone et organiser l’enlèvement des déchets.'],
                [1, AssignmentStatus::InProgress->value, 'Diagnostic électrique en cours avec l’équipe d’éclairage.'],
                [2, AssignmentStatus::Completed->value, 'Chaussée réparée et balisage retiré après contrôle.'],
                [3, AssignmentStatus::InProgress->value, 'Curage du canal planifié avec le service d’assainissement.'],
            ];

            foreach ($assignmentData as [$reportIndex, $status, $notes]) {
                $report = $persistedReports->get($reportIndex);

                Assignment::query()->updateOrCreate(
                    ['report_id' => $report->id, 'user_id' => $users->get('intervenant')->id],
                    ['status' => $status, 'notes' => $notes],
                );
            }

            $notifications = [
                ['citizen_1', 'Votre signalement concernant le canal est maintenant en cours de traitement.', false],
                ['citizen_2', 'Votre signalement « Lampadaires éteints » est en cours de traitement.', false],
                ['citizen_3', 'Le signalement concernant le nid-de-poule a été résolu.', true],
                ['intervenant', 'Une nouvelle intervention prioritaire vous a été affectée.', false],
                ['intervenant', 'Le manager a ajouté une note au dossier d’assainissement.', false],
                ['manager', 'Deux signalements à priorité haute attendent une vérification.', false],
                ['manager', 'Le rapport hebdomadaire des interventions est disponible.', true],
            ];

            foreach ($notifications as [$recipientKey, $message, $isRead]) {
                Notification::query()->updateOrCreate(
                    ['user_id' => $users->get($recipientKey)->id, 'message' => $message],
                    ['is_read' => $isRead],
                );
            }
        });
    }

    /**
     * @return array<string, array{name: string, email: string, password: string}>
     */
    private function validatedAccounts(): array
    {
        $accounts = config('production-demo.accounts');

        if (! is_array($accounts) || array_keys($accounts) !== array_keys(self::ACCOUNT_ROLES)) {
            throw new LogicException('La configuration des comptes de démonstration est incomplète.');
        }

        $emails = [];
        $passwords = [];

        foreach ($accounts as $key => $account) {
            $name = $account['name'] ?? null;
            $email = $account['email'] ?? null;
            $password = $account['password'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                throw new LogicException("Le nom du compte {$key} est obligatoire.");
            }

            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new LogicException("L’adresse e-mail du compte {$key} est invalide.");
            }

            if (! is_string($password) || mb_strlen($password) < 16) {
                throw new LogicException("Le mot de passe du compte {$key} doit contenir au moins 16 caractères.");
            }

            $emails[] = mb_strtolower($email);
            $passwords[] = $password;
        }

        if (count(array_unique($emails)) !== count($emails)) {
            throw new LogicException('Chaque compte de démonstration doit avoir une adresse e-mail distincte.');
        }

        if (count(array_unique($passwords)) !== count($passwords)) {
            throw new LogicException('Chaque compte de démonstration doit avoir un mot de passe distinct.');
        }

        return $accounts;
    }
}
