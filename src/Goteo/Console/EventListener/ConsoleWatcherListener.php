<?php
/*
 * This file is part of the Goteo Package.
 *
 * (c) Platoniq y Fundación Goteo <fundacion@goteo.org>
 *
 * For the full copyright and license information, please view the README.md
 * and LICENSE files that was distributed with this source code.
 */

namespace Goteo\Console\EventListener;

use Goteo\Application\Config;
use Goteo\Application\EventListener\AbstractListener;
use Goteo\Console\ConsoleEvents;
use Goteo\Console\Event\FilterProjectEvent;
use Goteo\Console\UsersSend;
use Goteo\Library\Feed;
use Goteo\Library\FeedBody;

use Goteo\Application\Exception\DuplicatedEventException;
use Goteo\Model\Project;
use Goteo\Model\Project\Reward;
use Goteo\Model\Blog;
use Goteo\Model\User;
use Goteo\Model\Event;

//

class ConsoleWatcherListener extends AbstractListener {

	private function logFeedEntry(Feed $log, Project $project) {
		if ($log->unique_issue) {
			$this->warning("Duplicated feed", [$project, $log]);
		} else {
			$this->notice("Populated feed", [$project, $log]);
		}
	}

    /**
     * Executes the action of sending a message to the targets
     * Ensures that the sending is a unique event so no duplicates messages arrives to the user
     *
     * @param  Project $project    Project object to process
     * @param  string  $template   Message identifier (from the UsersSend class)
     * @param  array   $target     Receiver, the owner or the consultants
     * @param  string  $extra_hash Used to add some extra identification to the Event action to allow sending the same message more than once
     */
    private function send(Project $project, $template, $target = ['owner'], $extra_hash = '') {
        if(!is_array($target)) $target = [$target];
        foreach($target as $to) {
            if(!in_array($to, ['owner', 'consultants'])) {
                throw new \LogicException("Target [$to] not allowed");
            }
            try {
                $action = [$project->id, $to, $template];
                if($extra_hash) $action[] = $extra_hash;
                $event = new Event($action);

            } catch(DuplicatedEventException $e) {
                $this->warning('Duplicated event', ['action' => $e->getMessage(), $project, 'event' => "$to:$template"]);
                return;
            }
            $event->fire(function() use ($project, $template, $to) {
                UsersSend::setURL(Config::getUrl($project->lang));
                if('owner' === $to) UsersSend::toOwner($template, $project);
                if('consultants' === $to) UsersSend::toConsultants($template, $project);
            });

            $this->notice("Sent message to $to", [$project, 'event' => "$to:$template"]);
        }
    }

    /**
     * Automatically publishes projects
     * @param  FilterProjectEvent $event
     */
    public function onProjectPublish(FilterProjectEvent $event) {
        $project = $event->getProject();
        $this->info("Automatic publish of project", [$project]);

        $errors = [];
        if(!$res = $project->publish($errors)) {
            $this->error('Error publishing project! '.implode("\n", $errors), [$project]);
        }

        // Admin Feed
        $log = new Feed();
        // We don't want to repeat this feed
        $log->setTarget($project->id)
            ->populate('feed-admin-new_project',
                '/admin/projects',
                new FeedBody(null, null, 'feed-project-published-' . ($res ? 'ok' : 'ko'), [
                    '%PROJECT%' => Feed::item('project', $project->name, $project->id),
                    '%DAYS%'    => $project->days,
                    '%ROUND%'   => $project->round
                ]),
                $project->image)
            ->doAdmin('admin');

        $this->logFeedEntry($log, $project);

        if($res) {
            $log->unique = true;
            $log->unique_issue = false;
            // Public event
            $log->title = $project->name;
            $log->url   = '/project/'.$project->id;
            $log->setBody(new FeedBody(null, null, 'feed-new_project'));
            $log->doPublic('projects');

            $this->logFeedEntry($log, $project);
        }
    }

    /**
     * Adds some actions for a ending-life project
     * Should be triggered when project is 5,3,2,1 days left to end round
     * @param  FilterProjectEvent $event
     */
    public function onProjectEnding(FilterProjectEvent $event) {
        $project = $event->getProject();

        // Public feed
        $log = new Feed();
        // We don't want to repeat this feed
        $log->unique = true;
        $log->setTarget($project->id)
        // Feed can handle Text objects automatically if an array is passed
            ->populate('feed-project-ending',
            '/admin/projects',
            new FeedBody(null, null, 'feed-project_runout', [
                    '%PROJECT%' => Feed::item('project', $project->name, $project->id),
                    '%DAYS%'    => $project->days,
                    '%ROUND%'   => $project->round
                ]
            ),
            $project->image)
            ->doAdmin('project');

        $this->logFeedEntry($log, $project);
        $log->unique_issue = false;
        // Public event
        $log->title = $project->name;
        $log->url   = '/project/'.$project->id;
        $log->doPublic('projects');

        $this->logFeedEntry($log, $project);
    }

    /**
     * Sends some advice to the owners
     * @param  FilterProjectEvent $event
     */
    public function onProjectActive(FilterProjectEvent $event) {
        $project = $event->getProject();
        $days_active = $event->getDays();
        $days_funded = $event->getDaysFunded();
        $contract_status=$event->getContractStatus();

        $this->info("Project in-campaign event", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);

        // CONTRACT DATA
        // primero los que no se bloquean
        //Solicitud de datos del contrato
        // TODO: to extend/...
        if( $days_funded >= 1 && $days_funded < 3) {
            // si ha superado el mínimo
            if ($project->amount >= $project->mincost) {
                $this->info("Requesting contract data", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded]);
                $this->send($project, '1d_after', ['owner']);
            }
        }

        if($days_funded == 15 && !$contract_status->owner) {
                $this->info("Contract form reminder", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded, 'contract_status' => $contract_status]);

                $this->send($project, '15d_after', ['owner']);
        }

        // ahora checkeamos bloqueo de consejos
        $prefs = User::getPreferences($project->owner);
        if ($prefs->tips) {
            $this->warning("Non sending campaign tips due user preferences", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
            return;
        }

        // E idioma de preferencia del impulsor
        $comlang = $prefs->comlang;

        // flag de aviso
        $blog_tip_sent = false;

        // TODO : se comentó que para proyectos con campañas cortas, los consejos se envien proporcionalmente
        // Consejos/avisos puntuales
        switch ($days_active) {

            // NO condicionales
            case 0: // Proyecto publicado
                $this->send($project, 'tip_0', ['owner', 'consultants']);
                break;
            case 1: // Difunde, difunde, difunde
            case 2: // Comienza por lo más próximo
            case 3: // Una acción a diario, por pequeña que sea
            case 4: // Llama a todas las puertas
            case 5: // Busca dónde está tu comunidad
                $this->send($project, "tip_$days_active", ['owner']);
                break;

            case 8:
            case 25:
                $this->send($project, "tip_8", ['owner']);
                break;

            // periodico condicional
            case 6: // Publica novedades!
            // y  se repite cada 6 días (fechas libres) mientras no haya posts
            case 18:
            case 28:
                // si ya hay novedades, nada
                if (Blog::hasUpdates($project->id)) {
                    $this->info("Project already has blog updates", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                } else {
                    $this->send($project, "any_update", ['owner'], $days_active);
                    $blog_tip_sent = true;
                }
                break;

            // comprobación periódica pero solo un envío
            case 7: // Apóyate en quienes te van apoyando, si más de 20 cofinanciadores
                // o en cuanto llegue a 20 backers (en fechas libres)
            case 26:
                if ($project->num_investors >= 20) {
                    $this->send($project, "20_backers", ['owner']);
                } else {
                    $this->warning("Not sending message to project with less than 20 backers", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                }
                break;

            case 10: // Luce tus recompensas y retornos
                // que no se envie a los que solo tienen recompensas de agradecimiento
                $thanksonly = true;
                // recompensas
                $rewards = Reward::getAll($project->id, 'individual', $comlang);
                foreach ($rewards as $rew) {
                    if ($rew->icon != 'thanks') {
                        $thanksonly = false;
                        break; // ya salimos del bucle, no necesitamos más
                    }
                }
                if ($thanksonly) {
                    $this->warning("Not sending message to project with thanks-only rewards", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                } else {
                    uasort($rewards,
                        function ($a, $b) {
                            if ($a->amount == $b->amount) return 0;
                            return ($a->amount > $b->amount) ? 1 : -1;
                            }
                        );
                    // sacar la primera y la última
                    $lower = reset($rewards); $project->lower = $lower->reward;
                    $higher = end($rewards); $project->higher = $higher->reward;

                    $this->send($project, "tip_10", ['owner']);
                }
                break;


            case 11: // Refresca tu mensaje de motivacion
                $this->send($project, "tip_11", ['owner']);
                break;

            case 15: // Sigue los avances y calcula lo que falta
                // si no ha llegado al mínimo
                if ($project->amount < $project->mincost) {
                    $this->send($project, "tip_15", ['owner']);
                } else {
                    $this->warning("Not sending message project as already has reached the minimum amount", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                }
                break;

            case 20: // No bajes la guardia!
                // si no ha llegado al mínimo
                if ($project->amount < $project->mincost) {
                    $this->send($project, "two_weeks", ['owner']);
                } else {
                    $this->warning("Not sending message project as already has reached the minimum amount", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                }
                break;

            case 32: // Al proyecto le faltan 8 días para archivarse
                // si no ha llegado al mínimo
                if ($project->amount < $project->mincost) {
                    $this->send($project, "8_days", ['owner']);
                } else {
                    $this->warning("Not sending message project as already has reached the minimum amount", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                }
                break;

            case 36: // Al proyecto le faltan 4 días para archivarse
                // si no ha llegado al mínimo pero está por encima del 70%
                if ($project->amount < $project->mincost && $project->percent >= 70) {
                    $this->send($project, "2_days", ['owner']);
                } else {
                    $this->warning("Not sending message to project as already has reached the minimum amount or more than 70%", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                }
                break;

            // Extra
            case 26: // Send information about contract in order to prepare documentacion
                $this->info("Sending information about contract", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded]);
                $this->send($project, "14_days", ['owner']);
                break;

            case 17:
                $this->send($project, "face_to_face_event", ['owner']);
                break;

            case 22:
                $this->send($project, "online_event", ['owner']);
                break;

            case 24:
                $this->send($project, "press", ['owner']);
                break;

            case 30:
                $this->send($project, "invest_in_social_networks", ['owner']);
                break;
        }
    }

    /**
     * Sends a reminder to the owners that they have to accomplish with the collective returns
     * @param  FilterProjectEvent $event
     */
    public function onProjectWatch(FilterProjectEvent $event) {
        $project = $event->getProject();
        $days_active = $event->getDays();
        $days_succeeded = $event->getDaysSucceeded();
        $days_funded = $event->getDaysFunded();
        $contract_status=$event->getContractStatus();

        $this->info("Project vigilant", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded]);

        // CONTRACT DATA for first round
        // primero los que no se bloquean
        //Solicitud de datos del contrato
        // TODO: to extend/...
        if( $project->one_round && $days_funded >= 1 && $days_funded < 3) {
            // si ha superado el mínimo
            if ($project->amount >= $project->mincost) {
                $this->info("Requesting contract data", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded]);
                $this->send($project, '1d_after', ['owner']);
            }
        }

        if($days_succeeded == 7) {
                $this->info("My story form available", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded]);

                $this->send($project, '7d_after', ['owner']);
        }

        if($project->one_round && $days_funded == 15 && !$contract_status->owner) {
                $this->info("Contract form reminder", [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded, 'contract_status' => $contract_status]);

                $this->send($project, '15d_after', ['owner']);
        }

        // die("[$days_funded]\n");
        // ~ 10 month ago
        if( $days_succeeded > 10 * 30 && $days_succeeded < 10 * 31) {
            if(!Reward::areFulfilled($project->id, 'social') && $project->status != Project::STATUS_FULFILLED) {
                $this->info('Found 10 month old project with non-social accomplished returns', [$project, 'days_active' => $days_active, 'days_funded' => $days_funded, 'days_succeeded' => $days_succeeded]);
                // Non social accomplished returns
                $this->send($project, 'commons', ['consultants']);
            } else {
                $this->warning("Not sending message to consultants with fulfilled social rewards after 10 months", [$project, 'days_succeeded' => $days_active]);
            }
        }

        // Recuerdo al autor proyecto, 2 meses despues de campaña finalizada
        if ( $days_succeeded >= 2 * 30 && $days_succeeded < 2 * 31) {
            // si quedan recompensas/retornos pendientes por cumplir
            if (!Reward::areFulfilled($project->id) || !Reward::areFulfilled($project->id, 'social') ) {
                $this->send($project, '2m_after', ['owner']);
            } else {
                $this->warning("Not sending message to project with fulfilled rewards after 2 months", [$project, 'days_succeeded' => $days_active]);
            }
        }

        // Recuerdo al autor proyecto, 8 meses despues de campaña finalizada
        if ( $days_succeeded > 8 * 30 && $days_succeeded < 8 * 31) {
            // si quedan retornos pendientes por cumplir
            if (!Reward::areFulfilled($project->id, 'social') ) {
                $this->send($project, '8m_after', ['owner']);
            } else {
                $this->warning("Not sending message to project with fulfilled social rewards after 8 months", [$project, 'days_succeeded' => $days_active]);
            }
        }


    }

	public static function getSubscribedEvents() {
		return array(
            ConsoleEvents::PROJECT_PUBLISH    => ['onProjectPublish', 100],
            ConsoleEvents::PROJECT_ENDING    => 'onProjectEnding',
            ConsoleEvents::PROJECT_ACTIVE    => 'onProjectActive',
			ConsoleEvents::PROJECT_WATCH    => 'onProjectWatch',
		);
	}
}
