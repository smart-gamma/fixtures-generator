#Smart Gamma Fixtures generator

Allow speed up fixtures creation by generating fixtures classes from project's database

##Configuration:

add to app/config.yml

gamma_fixtures_generator:
  fixture_references_file_name: /src/Prefix/SiteBundle/Resources/config/fixtureReferences.txt

##Fixtures generator usage:

```
app/console gamma:fixtures:generate "\Gamma\Bundle\Entity\Item" - generate all entities from table
app/console gamma:fixtures:generate "\Gamma\Bundle\Entity\Item" --id="1,2,3" - generate  entities with ids 1,2,3 from table
app/console gamma:fixtures:generate "\Gamma\Bundle\Entity\Item" --id="1,2,3" --force-add-reference - generate entities with ids 1,2,3 from table and add txt reference  
```

Note: Relations ManyToMany should be created additionally manually


