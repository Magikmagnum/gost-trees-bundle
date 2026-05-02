<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Affiche l'état de résolution d'une entité fantomisable :
 * pour chaque attribut fantomisable, indique si la valeur est locale,
 * héritée du parent, ou non définie.
 *
 *   $ php bin/console debug:ghosts "App\Entity\Trajet" 42
 */
#[AsCommand(
    name: 'debug:ghosts',
    description: 'Inspecte l\'état de résolution d\'une entité fantôme.'
)]
final class DebugGhostsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GhostResolverInterface $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'FQCN de l\'entité (ex: "App\\Entity\\Trajet")')
            ->addArgument('id', InputArgument::REQUIRED, 'Identifiant de l\'entité');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $class = $input->getArgument('class');
        $id = $input->getArgument('id');

        if (!class_exists($class)) {
            $io->error(sprintf('Classe "%s" introuvable.', $class));
            return Command::FAILURE;
        }

        $entity = $this->em->find($class, $id);
        if (null === $entity) {
            $io->error(sprintf('Aucune entité %s avec id=%s.', $class, $id));
            return Command::FAILURE;
        }

        if (!$entity instanceof GhostableInterface) {
            $io->error(sprintf('La classe "%s" n\'implémente pas GhostableInterface.', $class));
            return Command::FAILURE;
        }

        $io->title(sprintf('%s #%s', $class, $id));

        $parent = $entity->getParent();
        if (null === $parent) {
            $io->writeln('<info>Statut :</info> racine (pas de parent)');
        } else {
            $io->writeln(sprintf(
                '<info>Statut :</info> fantôme de %s #%s',
                $parent::class,
                method_exists($parent, 'getId') ? (string) $parent->getId() : '?'
            ));
        }

        $resolution = $this->resolver->debugResolution($entity);

        if (empty($resolution)) {
            $io->warning('Aucun champ marqué #[GhostableField] dans cette entité.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($resolution as $name => $info) {
            $rows[] = [
                $name,
                $this->stringify($info['value']),
                match ($info['source']) {
                    'local' => '<fg=green>MATÉRIALISÉ</>',
                    'inherited' => sprintf('<fg=cyan>FANTÔME ← niveau %d</>', $info['depth']),
                    default => '<fg=gray>non défini</>',
                },
            ];
        }

        $io->table(['Attribut', 'Valeur résolue', 'Origine'], $rows);

        $materialized = $this->resolver->isMaterialized($entity);
        $io->writeln(sprintf(
            '<info>Matérialisation :</info> %s',
            $materialized ? 'partielle ou totale' : 'aucune (entièrement transparent)'
        ));

        return Command::SUCCESS;
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '<fg=gray>null</>';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_object($value)) {
            return sprintf('%s#%s', $value::class, method_exists($value, 'getId') ? (string) $value->getId() : '?');
        }
        return get_debug_type($value);
    }
}
