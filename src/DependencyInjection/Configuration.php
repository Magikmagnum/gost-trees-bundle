<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Schéma de configuration du bundle.
 *
 * Expose les trois leviers principaux :
 * - max_depth : profondeur maximale de la hiérarchie fantôme
 * - on_root_delete : stratégie de suppression d'une racine
 * - auto_propagate_collections : propagation structurelle automatique
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ghost_trees');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('max_depth')
                    ->defaultValue(1)
                    ->min(1)
                    ->info('Profondeur maximale de la hiérarchie fantôme. 1 = un seul niveau de fantômes sous la racine.')
                ->end()
                ->enumNode('on_root_delete')
                    ->values(['cascade', 'incarnate'])
                    ->defaultValue('cascade')
                    ->info('Stratégie lors de la suppression d\'une racine : "cascade" supprime tous les fantômes, "incarnate" matérialise les fantômes en racines autonomes.')
                ->end()
                ->booleanNode('auto_propagate_collections')
                    ->defaultTrue()
                    ->info('Crée automatiquement les fantômes correspondants lors de l\'ajout d\'éléments dans une collection liée à la racine.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
