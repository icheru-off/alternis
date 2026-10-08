<?php
/**
 * Notes de version affichées aux utilisateurs existants à la connexion, une
 * seule fois par version (mémorisé via la préférence "seen_release").
 *
 * Pour publier de nouvelles notes : incrémentez RELEASE_ID et ajoutez une
 * entrée en tête du tableau. Chaque « slide » a une icône (emoji), un titre,
 * un texte, et un dégradé de fond.
 */

// Identifiant de la salve de notes en cours. À incrémenter à chaque nouveauté
// que l'on veut annoncer. Les utilisateurs qui ont déjà vu cet id ne le
// reverront pas.
if (!defined('RELEASE_ID')) define('RELEASE_ID', '2025-06');

/**
 * @return array{id:string,version:string,slides:array<int,array<string,string>>}
 */
function release_notes(): array
{
    return [
        'id'      => RELEASE_ID,
        'version' => '2.5',
        'slides'  => [
            [
                'icon'  => '✨',
                'tag'   => 'Nouveau',
                'title' => 'Stage ou alternance, au choix',
                'body'  => "Alternis s'adapte à votre situation. Choisissez « stage » ou « alternance » à l'inscription, et basculez à tout moment depuis vos réglages : le vocabulaire de l'application suit.",
                'grad'  => 'linear-gradient(135deg,#7C5CFC,#22D3EE)',
            ],
            [
                'icon'  => '🤝',
                'tag'   => 'Amélioré',
                'title' => 'Collaboration fluidifiée',
                'body'  => "Invitez un camarade, un proche ou un tuteur à suivre votre recherche. Les invitations s'acceptent maintenant en un clic, et chacun garde le contrôle des accès.",
                'grad'  => 'linear-gradient(135deg,#22D3EE,#34D399)',
            ],
            [
                'icon'  => '🎯',
                'tag'   => 'Nouveau',
                'title' => 'Un tutoriel qui vous guide',
                'body'  => "À la première connexion, un guide pas à pas vous accompagne pour créer votre première candidature, directement sur l'interface. Réactivable depuis les réglages.",
                'grad'  => 'linear-gradient(135deg,#F59E0B,#7C5CFC)',
            ],
            [
                'icon'  => '🧩',
                'tag'   => 'Extension',
                'title' => 'Capture depuis vos sites d\'emploi',
                'body'  => "L'extension navigateur enregistre vos candidatures en un clic depuis LinkedIn, Indeed, HelloWork et bien d'autres — et détecte même l'envoi automatiquement.",
                'grad'  => 'linear-gradient(135deg,#7C5CFC,#EC4899)',
            ],
        ],
    ];
}
