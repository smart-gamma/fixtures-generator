# Smart Gamma Fixtures generator Symfony bundle

Allow speed up fixtures creation by generating fixtures classes from project's database

## Install
```
composer require --dev gamma/fixtures-generator
```

Add to AppKernel.php to dev section:
```
$bundles[] = new Gamma\FixturesGeneratorBundle\GammaFixturesGeneratorBundle();
```

##Configuration:

add to app/config.yml

gamma_fixtures_generator:

  fixture_references_file_name: /var/fixtureReferences.txt

## Fixtures generator usage:

```
app/console gamma:fixtures:generate "\Gamma\Bundle\Entity\Item" - generate all entities from table
app/console gamma:fixtures:generate "\Gamma\Bundle\Entity\Item" --id="1,2,3" - generate  entities with ids 1,2,3 from table
app/console gamma:fixtures:generate "\Gamma\Bundle\Entity\Item" --id="1,2,3" --force-add-reference - generate entities with ids 1,2,3 from table and add txt reference  
```

## Notes 

1.Despite of the autoincrement generator is reset if you use schema recreate before fixture loading, better stick to defined ids via direct setter 

Add for each fixture class:
```
        $metadata = $manager->getClassMetaData('\Gamma\Bundle\Entity\Item');
        $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
```

2. Relations ManyToMany should be created additionally manually
