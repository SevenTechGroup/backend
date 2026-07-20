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

class DemoDataSeeder extends Seeder
{
    public const MANAGER_EMAIL = 'manager@sahelsignal.local';

    public const AGENT_EMAIL = 'agent@sahelsignal.local';

    public const CITIZEN_EMAIL = 'citoyen@sahelsignal.local';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Les données de démonstration sont réservées aux environnements local et testing.');
        }

        DB::transaction(function (): void {
            $manager = $this->user(
                name: 'Aminata Ndiaye',
                email: self::MANAGER_EMAIL,
                password: 'Manager@2026!',
                role: UserRole::Manager,
            );
            $agent = $this->user(
                name: 'Moussa Diop',
                email: self::AGENT_EMAIL,
                password: 'Agent@2026!',
                role: UserRole::Agent,
            );
            $citizen = $this->user(
                name: 'Ousseynou Faye',
                email: self::CITIZEN_EMAIL,
                password: 'Citoyen@2026!',
                role: UserRole::Citizen,
            );

            $categories = Category::query()->get()->keyBy('slug');
            $territories = Territory::query()->get()->keyBy('code');

            $reports = [
                [
                    'title' => 'Dépôt sauvage près du marché Sandaga',
                    'description' => 'Un important dépôt de déchets bloque le passage des piétons depuis plusieurs jours près de l’entrée du marché.',
                    'category' => 'dechets',
                    'territory' => 'DKR',
                    'location_text' => 'Entrée sud du marché Sandaga',
                    'priority' => ReportPriority::High->value,
                    'status' => ReportStatus::Received->value,
                ],
                [
                    'title' => 'Lampadaires éteints dans la rue principale',
                    'description' => 'Quatre lampadaires ne fonctionnent plus et toute la rue reste sombre après 20 heures.',
                    'category' => 'eclairage-public',
                    'territory' => 'GWD',
                    'location_text' => 'Rue principale, derrière la mairie',
                    'priority' => ReportPriority::Medium->value,
                    'status' => ReportStatus::InProgress->value,
                ],
                [
                    'title' => 'Nid-de-poule dangereux sur la chaussée',
                    'description' => 'Un trou profond occupe une partie de la voie et oblige les véhicules à effectuer un écart dangereux.',
                    'category' => 'voirie',
                    'territory' => 'PKN',
                    'location_text' => 'Route de Pikine, près du terminus',
                    'priority' => ReportPriority::High->value,
                    'status' => ReportStatus::Resolved->value,
                ],
                [
                    'title' => 'Canal d’évacuation bouché après les pluies',
                    'description' => 'Le canal ne laisse plus passer l’eau et plusieurs habitations commencent à être touchées par la montée des eaux.',
                    'category' => 'eau-assainissement',
                    'territory' => 'DKR',
                    'location_text' => 'Quartier Grand-Yoff, zone de captage',
                    'priority' => ReportPriority::High->value,
                    'status' => ReportStatus::InProgress->value,
                ],
                [
                    'title' => 'Animal blessé sur la voie publique',
                    'description' => 'Un animal blessé est immobilisé au bord de la route et nécessite une prise en charge rapide.',
                    'category' => 'sante-animale',
                    'territory' => 'PKN',
                    'location_text' => 'À proximité du rond-point Tally Bou Mack',
                    'priority' => ReportPriority::Medium->value,
                    'status' => ReportStatus::Received->value,
                ],
                [
                    'title' => 'Bac de collecte remplacé avec succès',
                    'description' => 'Le bac endommagé signalé la semaine dernière a été remplacé et la zone a été entièrement nettoyée.',
                    'category' => 'dechets',
                    'territory' => 'GWD',
                    'location_text' => 'Quartier Hamo 4, près de l’école',
                    'priority' => ReportPriority::Low->value,
                    'status' => ReportStatus::Resolved->value,
                ],
            ];

            $persistedReports = collect($reports)->map(function (array $data) use ($categories, $territories, $citizen): Report {
                $category = $categories->get($data['category']);
                $territory = $territories->get($data['territory']);

                if (! $category || ! $territory) {
                    throw new LogicException('Les référentiels de démonstration doivent être créés avant les signalements.');
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
                if (! $report) {
                    continue;
                }

                Assignment::query()->updateOrCreate(
                    ['report_id' => $report->id, 'user_id' => $agent->id],
                    ['status' => $status, 'notes' => $notes],
                );
            }

            $notifications = [
                [$citizen, 'Votre signalement « Lampadaires éteints » est maintenant en cours de traitement.', false],
                [$citizen, 'Le signalement concernant le nid-de-poule a été résolu.', true],
                [$agent, 'Une nouvelle intervention prioritaire vous a été affectée.', false],
                [$agent, 'Le responsable a ajouté une note au dossier d’assainissement.', false],
                [$manager, 'Deux signalements à priorité haute attendent une vérification.', false],
                [$manager, 'Le rapport hebdomadaire des interventions est disponible.', true],
            ];

            foreach ($notifications as [$recipient, $message, $isRead]) {
                Notification::query()->updateOrCreate(
                    ['user_id' => $recipient->id, 'message' => $message],
                    ['is_read' => $isRead],
                );
            }
        });
    }

    private function user(string $name, string $email, string $password, UserRole $role): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => $role->value,
                'email_verified_at' => now(),
            ],
        );
    }
}
