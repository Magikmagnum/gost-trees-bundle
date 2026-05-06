<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Extension chargée par Symfony pour activer le bundle.
 *
 * Charge le fichier services.yaml et publie la configuration utilisateur
 * sous forme de paramètres de conteneur (ghost_trees.max_depth, etc.).
 */
final class GhostTreesExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('ghost_trees.max_depth', $config['max_depth']);
        $container->setParameter('ghost_trees.on_root_delete', $config['on_root_delete']);
        $container->setParameter('ghost_trees.auto_propagate_collections', $config['auto_propagate_collections']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config'),
        );
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'ghost_trees';
    }
}
