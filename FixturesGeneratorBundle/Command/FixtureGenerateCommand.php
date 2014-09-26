<?php

namespace Gamma\FixturesGenerator\FixturesGeneratorBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * FixtureGenerateCommand
 *
 * @author Leo Dubchuck <leo.dbchk@gmail.com>
 */
class FixtureGenerateCommand extends ContainerAwareCommand
{
    /** @var \Symfony\Component\Console\Output\OutputInterface */
    private $output;
    
    /** @var \Doctrine\ORM\EntityManager\EntityManager */
    private $em;

    /** @var \Doctrine\ORM\Mapping\ClassMetadataFactory */
    private $metadataFactory;
    
    /** @var boolean */
    private $forceAddReference;
    
    /** @var array */
    private $classCache;
    
    /** @var array */
    private $referenceCache;
    
    protected function configure()
    {
        $this
            ->setName('gamma:fixtures:generate')
            ->setDescription('Generates fixtures for entity given')
            ->addArgument('entity', InputArgument::REQUIRED, 'Entity class')
            ->addOption('filter-dql', null, InputOption::VALUE_REQUIRED, 'Filter DQL expression')
            ->addOption('property', null, InputOption::VALUE_REQUIRED, 'Filter property')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of filter IDs')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Row count limit')
            ->addOption('force-add-reference', null, InputOption::VALUE_NONE, 'If set, reference will be added for each entity')
        ;
    }
    
    protected function execute(InputInterface $input = null, OutputInterface $output = null)
    {
        $this->output = $output;
        $this->em = $this->getContainer()->get('doctrine')->getManager();
        $this->metadataFactory = $this->em->getMetadataFactory();

        /* @var $inputArgumentEntity string */
        $inputArgumentEntity = $input->getArgument('entity');
        
        /* @var $inputOptionFilterDql string */
        $inputOptionFilterDql = $input->getOption('filter-dql');

        /* @var $inputOptionProperty string */
        $inputOptionProperty = $input->getOption('property');

        /* @var $inputOptionId string */
        $inputOptionId = $input->getOption('id');

        /* @var $inputOptionLimit string */
        $inputOptionLimit = $input->getOption('limit');

        /* @var $inputForceAddReference boolean */
        $inputForceAddReference = $input->getOption('force-add-reference');

        $this->forceAddReference = $inputForceAddReference;
        
        $this->classCache = array();

        $configPath = $this->getContainer()->getParameter('gamma_fixtures_generator.fixture_references_file_name');
        if($configPath == 'fixtureReferences.txt'){
            $configPath = $this->getContainer()->get('kernel')->getRootDir(). '/../'.$configPath;
        }
        $fixtureReferencesFileName = $configPath;

        $this->referenceCache = \explode("\n", \file_get_contents($fixtureReferencesFileName));
        
        /* @var $variable string */
        $variable = $this->generateVariable($inputArgumentEntity);

        /* @var $qb \Doctrine\ORM\QueryBuilder */
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from($inputArgumentEntity, 'e')
        ;
        if ($inputOptionFilterDql) {
            $qb
                ->where($inputOptionFilterDql)
            ;
        } else if ($inputOptionId) {
            $property = ($inputOptionProperty) ? $inputOptionProperty : 'id';
            
            /* @var $idValues array */
            $idValues = \explode(',', $inputOptionId);
        
            $qb
                ->where($qb->expr()->in('e.'.$property, $idValues))
            ;
        }
        if ($inputOptionLimit) {
            $qb
                ->setMaxResults($inputOptionLimit)
            ;
        }
        
        /* @var $query \Doctrine\ORM\Query */
        $query = $qb->getQuery();
        
        /* @var $entities array */
        $entities = $query->getResult();

        foreach ($entities as $entity) {
            $this->writeFixturePreparation($entity);
        }
        
        foreach ($entities as $entity) {
            $this->writeFixture($entity, $variable);
        }
        
        \file_put_contents($fixtureReferencesFileName, \implode("\n", $this->referenceCache));
    }
    
    /**
     * @param mixed $entity
     */
    private function writeFixturePreparation($entity)
    {
        /* @var $class string */
        $class = $this->getClass($entity);

        /* @var $metadata \Doctrine\ORM\Mapping\ClassMetadataInfo */
        $metadata = $this->metadataFactory->getMetadataFor($class);

        if (!$this->isClassCached($class)) {
            $this->writeRelatedFixturePreparations($entity, $metadata);
            
            if ($this->entityHasWritableId($entity)) {
                /* @var $entityClass string */
                $entityClass = $this->getClass($entity);

                $this->writeLine('$metadata = $manager->getClassMetaData('."'".$entityClass."'".');');
                $this->writeLine('$metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);');

                $this->writeBlankLine();
            }
            
            if (!$this->isClassCached($class)) {
                $this->cacheClass($class);
            }
        }
    }
        
    /**
     * @param mixed $entity
     * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
     */
    private function writeRelatedFixturePreparations($entity, $metadata)
    {
        foreach ($metadata->getAssociationNames() as $associationName) {
            $associationMapping = $metadata->getAssociationMapping($associationName);
            if (
                $associationMapping['isOwningSide'] 
                && 
                in_array($associationMapping['type'], array(ClassMetadataInfo::ONE_TO_ONE, ClassMetadataInfo::MANY_TO_ONE))
            ) {
                /* @var $associationNameCapitalized string */
                $associationNameCapitalized = \ucfirst($associationName);

                /* @var $getterName string */
                $getterName = 'get'.$associationNameCapitalized;
                
                /* @var $associationEntity mixed|null */
                $associationEntity = $entity->$getterName();
                
                if ($associationEntity) {
                    /* @var $associationClass string */
                    $associationClass = $associationMapping['targetEntity'];

                    if (!$this->isClassCached($associationClass)) {
                        /**
                         * jekccs: we need to check cache before reccursive call  
                         */
                        if (!$this->isClassCached($associationClass)) {
                            $this->cacheClass($associationClass);
                        }
                        $this->writeFixturePreparation($associationEntity);
                        /*
                        if (!$this->isClassCached($associationClass)) {
                            $this->cacheClass($associationClass);
                        }*/
                    }
                }
            }
        }
    }
    
    /**
     * @param mixed $entity
     * @param string $variable
     */
    private function writeFixture($entity, $variable)
    {
        /* @var $class string */
        $class = $this->getClass($entity);
        
        /* @var $metadata \Doctrine\ORM\Mapping\ClassMetadataInfo */
        $metadata = $this->metadataFactory->getMetadataFor($class);

        /* @var $reference string */
        $reference = $this->generateReference($entity, $metadata);
        
        if (!$this->isReferenceCached($reference)) {
            $this->writeRelatedFixtures($entity, $metadata);

            $this->writeEntityCreation($variable, $class);
            $this->writeEntityProperties($variable, $entity, $metadata);
            $this->writeEntityRelations($variable, $entity, $metadata);
            $this->writeEntityPersist($variable);

            if (($this->forceAddReference || $this->entityHasSelfReferences($metadata)) && !$this->isReferenceCached($reference)) {
                $this->writeBlankLine();
                $this->writeEntityReferenceAdd($reference, $variable);

                $this->cacheReference($reference);
            }
        
            $this->writeBlankLine();
        }
    }
    
    /**
     * @param mixed $entity
     * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
     */
    private function writeRelatedFixtures($entity, $metadata)
    {
        foreach ($metadata->getAssociationNames() as $associationName) {
            $associationMapping = $metadata->getAssociationMapping($associationName);
            if (
                $associationMapping['isOwningSide'] 
                && 
                in_array($associationMapping['type'], array(ClassMetadataInfo::ONE_TO_ONE, ClassMetadataInfo::MANY_TO_ONE))
            ) {
                /* @var $associationNameCapitalized string */
                $associationNameCapitalized = \ucfirst($associationName);

                /* @var $getterName string */
                $getterName = 'get'.$associationNameCapitalized;
                
                /* @var $associationEntity mixed|null */
                $associationEntity = $entity->$getterName();
                
                if ($associationEntity) {
                    /* @var $associationClass string */
                    $associationClass = $associationMapping['targetEntity'];

                    /* @var $associationMetadata \Doctrine\ORM\Mapping\ClassMetadataInfo */
                    $associationMetadata = $this->metadataFactory->getMetadataFor($associationClass);
                    
                    /* @var $reference string */
                    $reference = $this->generateReference($associationEntity, $associationMetadata);
                    
                    if (!$this->isReferenceCached($reference)) {
                        $this->writeFixture($associationEntity, $associationName);

                        if (!$this->isReferenceCached($reference)) {
                            $this->writeEntityReferenceAdd($reference, $associationName);
                            $this->writeBlankLine();

                            $this->cacheReference($reference);
                        }
                    }
                }
            }
        }
    }
    
    /**
     * @param string $variable
     * @param string $class
     */
    private function writeEntityCreation($variable, $class)
    {
        $this->writeLine('$'.$variable.' = new \\'.$class.'();');
    }

    /**
     * @param string $variable
     * @param mixed $entity
     * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
     */
    private function writeEntityProperties($variable, $entity, $metadata)
    {
        /* @var $entityReflection \ReflectionClass */
        $entityReflection = new \ReflectionClass($entity);
        
        foreach ($metadata->getFieldNames() as $fieldName) {
            /* @var $associationNameCapitalized string */
            $fieldNameCapitalized = \ucfirst($fieldName);

            /* @var $getterName string */
            $getterName = 'get'.$fieldNameCapitalized;

            /* @var $associationEntity mixed|null */
            $fieldValue = $entity->$getterName();
            
            /* @var $fieldMapping array */
            $fieldMapping = $metadata->getFieldMapping($fieldName);
            
            /* @var $fieldType string */
            $fieldType = $fieldMapping['type'];
            
            if (\strcasecmp($fieldName, 'id') == 0 && $fieldType == 'integer') {
                /* @var $setterName string */
                $setterName = 'set'.$fieldNameCapitalized;
                
                if (!$entityReflection->hasMethod($setterName)) {
                    continue;
                }
            }
            
            /* @var $fieldValueStr string */
            $fieldValueStr = 'null';
            
            // todo: representing field value in a string should be refactored
            if ($fieldValue) {
                if (\in_array($fieldType, array('string', 'text'))) {
                    if (\is_string($fieldValue)) {
                        $fieldValueStr = "'".\addslashes($fieldValue)."'";
                    } else if (\is_array($fieldValue)) {
                        $fieldValueStr = "'".\addslashes(json_encode($fieldValue))."'";
                    }
                } else if ($fieldType === 'boolean') {
                    $fieldValueStr = $fieldValue ? 'true' : 'false';
                } else if ($fieldType === 'datetime') {
                    $fieldValueStr = "new \DateTime('".$fieldValue->format('Y-m-d H:i:s')."')";
                } else if ($fieldType === 'date') {
                    $fieldValueStr = "new \DateTime('".$fieldValue->format('Y-m-d')."')";
                } else if ($fieldType == 'array') {
                    $fieldValueStr = "'".\addslashes(json_encode($fieldValue))."'";
                } else {
                    $fieldValueStr = $fieldValue;
                }
            } else if (\array_key_exists('nullable', $fieldMapping) && !$fieldMapping['nullable']) {
                if ($fieldType === 'boolean') {
                    $fieldValueStr = 'false';
                } else if (\in_array($fieldType, array('string', 'text'))) {
                    $fieldValueStr = "''";
                } else {
                    $fieldValueStr = '0';
                }
            }
            
            if ($fieldValueStr !== 'null') {
                /* @var $fieldNameCapitalized string */
                $fieldNameCapitalized = \ucfirst($fieldName);

                /* @var $setterMethod string */
                $setterMethod = 'set'.$fieldNameCapitalized;

                $this->writeLine('$'.$variable.'->'.$setterMethod.'('.$fieldValueStr.');');
            }
        }
    }
    
    /**
     * @param string $variable
     * @param mixed $entity
     * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
     */
    private function writeEntityRelations($variable, $entity, $metadata)
    {
        foreach ($metadata->getAssociationNames() as $associationName) {
            /* @var $associationMapping array */
            $associationMapping = $metadata->getAssociationMapping($associationName);
            
            if (
                $associationMapping['isOwningSide'] 
                && 
                in_array($associationMapping['type'], array(ClassMetadataInfo::ONE_TO_ONE, ClassMetadataInfo::MANY_TO_ONE))
            ) {
                /* @var $associationValue mixed|null */
                $associationValue = $metadata->getFieldValue($entity, $associationName);

                if ($associationValue) {
                    /* @var $associationClass string */
                    $associationClass = $associationMapping['targetEntity'];
                    
                    /* @var $associationMetadata \Doctrine\ORM\Mapping\ClassMetadataInfo */
                    $associationMetadata = $this->metadataFactory->getMetadataFor($associationClass);
                    
                    /* @var $reference string */
                    $reference = $this->generateReference($associationValue, $associationMetadata);
                    
                    $this->writeEntityReferenceGet($variable, $associationName, $reference);
                }
            }
        }
    }

    /**
     * @param string $variable
     */
    private function writeEntityPersist($variable)
    {
        $this->writeLine('$manager->persist($'.$variable.');');
    }
    
    /**
     * @param string $reference
     * @param string $variable
     */
    private function writeEntityReferenceAdd($reference, $variable)
    {
        $this->writeLine('$this->addReference(\''.$reference.'\', $'.$variable.');');
    }
    
    /**
     * @param string $variable
     * @param string $property
     * @param string $reference
     */
    private function writeEntityReferenceGet($variable, $property, $reference)
    {
        $this->writeLine('$'.$variable.'->set'.\ucfirst($property).'($this->getReference(\''.$reference.'\'));');
    }
    
    /**
     * @param string $line
     */
    private function writeLine($line)
    {
        $this->output->writeln(\str_repeat(' ', 8).$line);
    }
    
    private function writeBlankLine()
    {
        $this->output->writeln('');
    }
    
    /**
     * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
     * 
     * @return boolean
     */
    private function entityHasSelfReferences($metadata)
    {
        foreach ($metadata->getAssociationNames() as $associationName) {
            /* @var $associationMapping array */
            $associationMapping = $metadata->getAssociationMapping($associationName);
            if (
                $associationMapping['isOwningSide'] 
                && 
                in_array($associationMapping['type'], array(ClassMetadataInfo::ONE_TO_ONE, ClassMetadataInfo::MANY_TO_ONE))
                &&
                $associationMapping['targetEntity'] === $associationMapping['sourceEntity']
            ) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * @param mixed $entity
     */
    private function entityHasWritableId($entity)
    {
        /* @var $entityReflection \ReflectionClass */
        $entityReflection = new \ReflectionClass($entity);
        
        return ($entityReflection->hasMethod('setId'));
    }
    
    /**
     * @param string $entity
     * 
     * @return string
     */
    private function getClass($entity)
    {
        return \str_replace('Proxies\\__CG__\\', '', \get_class($entity));
    }

    /**
     * @param string $class
     * 
     * @return boolean
     */
    private function isClassCached($class)
    {
        return \in_array($class, $this->classCache);
    }
    
    /**
     * @param string $class
     */
    private function cacheClass($class)
    {
        $this->classCache[] = $class;
    }
    
    /**
     * @param string $class
     * 
     * @return string
     */
    private function generateVariable($class)
    {
        return \lcfirst(\substr(\strrchr($class, '\\'), 1));
    }

    /**
     * @param mixed $entity
     * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
     * 
     * @return type
     */
    private function generateReference($entity, $metadata) 
    {
        return $metadata->rootEntityName.'-'.$entity->getId();
    }
    
    /**
     * @param string $reference
     * 
     * @return boolean
     */
    private function isReferenceCached($reference)
    {
        return \in_array($reference, $this->referenceCache);
    }
    
    /**
     * @param string $reference
     */
    private function cacheReference($reference)
    {
        $this->referenceCache[] = $reference;
    }
}
