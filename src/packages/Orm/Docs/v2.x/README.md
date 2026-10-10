# ORM

The ORM package (`src/packages/Orm`) is a data mapper built on the Database component: entities are plain PHP classes mapped with attributes, and the ORM (`OrmManagerInterface`, the entity manager) tracks them and writes the changes on `flush()`.
It ships repositories, query builders, lifecycle events, code generators and migrations, and works with MySQL / MariaDB, PostgreSQL and SQLite.

## Summary

- [Module](#module)
- [Configuration](#configuration)
- [Entities](#entities)
- [Relations](#relations)
- [Inheritance](#inheritance)
- [Collections](#collections)
- [Persisting](#persisting)
- [Repositories](#repositories)
- [Query builder](#query-builder)
- [Entities in controllers](#entities-in-controllers)
- [Lifecycle callbacks and events](#lifecycle-callbacks-and-events)
- [Forms](#forms)
- [Generating code](#generating-code)
- [Migrations](#migrations)
- [Commands](#commands)
- [Exceptions](#exceptions)
- [Limits](#limits)
- [Profiler](#profiler)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Package\Orm\OrmManager` (`final`) |
| Interface | `NeoPHP\Package\Orm\OrmManagerInterface` |
| Attribute | `#[Package(provider: OrmProvider::class, requires: [DatabaseManager::class])]` |
| Requires | Database |

Inject `OrmManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Package\Orm\OrmManagerInterface;

public function __construct(private OrmManagerInterface $orm)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Package\Orm\OrmManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Configuration

`config/packages/orm.yaml` (every key is optional, these are the defaults):

```yaml
connection: ~

entity:
    path: src/Entity
    namespace: App\Entity

repository:
    path: src/Repository
    namespace: App\Repository

migration:
    path: migrations
    namespace: Migrations
    table: neo_migrations

proxy:
    path: '%kernel.cache_path%/orm/proxies'

ignore_tables: []
```

| Key | Description |
|---|---|
| `connection` | database connection used by the ORM (`database.yaml`); the default connection when empty |
| `entity` | directory and namespace of the entities, used by `make:entity` and `make:migration` |
| `repository` | directory and namespace of the repositories, used by `make:repository` and to find the repository of an entity |
| `migration` | directory, namespace and table of the migrations |
| `proxy` | directory of the generated proxy classes (cleared by `cache:clear`) |
| `ignore_tables` | tables ignored by `make:migration` (never created nor dropped); the tables of the framework (`cache_items`, `remember_me_tokens`) are always ignored |

## Entities

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PostStatus;
use App\Repository\PostRepository;
use DateTimeImmutable;
use NeoPHP\Package\Orm\Collection\ArrayCollection;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use NeoPHP\Package\Orm\Mapping as ORM;

#[ORM\Entity(repository: PostRepository::class)]
#[ORM\Index(columns: ['status', 'publishedAt'])]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column]
    private PostStatus $status = PostStatus::Draft;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(Category::class, inversedBy: 'posts', nullable: false)]
    private ?Category $category = null;

    #[ORM\ManyToMany(Tag::class, inversedBy: 'posts')]
    private CollectionInterface $tags;

    #[ORM\OneToMany(Comment::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true, orderBy: ['createdAt' => 'DESC'])]
    private CollectionInterface $comments;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->comments = new ArrayCollection();
    }
}
```

The table is the class name in snake_case (`BlogPost` → `blog_post`) and each column is the property name in snake_case (`publishedAt` → `published_at`). `#[ORM\Entity(table: 'users')]` and `#[ORM\Column(name: '...')]` change them.

| Attribute | Options |
|---|---|
| `#[ORM\Entity]` | `table`, `repository` |
| `#[ORM\Id]` | the identifier (one per entity) |
| `#[ORM\GeneratedValue]` | `strategy: 'auto'` (auto increment, default), `'uuid'` (UUID v7 generated on `persist()`), `'none'` (set by the application) |
| `#[ORM\Column]` | `name`, `type`, `length` (255), `nullable` (false), `unique`, `default`, `precision` / `scale` (decimal), `enumType` |
| `#[ORM\Index]` | `columns` (properties or columns), `name`, `unique`; repeatable, on the class |

The type is deduced from the property type when it is not given:

| Type | PHP type | Deduced from |
|---|---|---|
| `string` | `string` | `string` |
| `text` | `string` | |
| `integer`, `smallint`, `bigint` | `int` | `int` |
| `float` | `float` | `float` |
| `decimal` | `string` (formatted with the scale) | |
| `boolean` | `bool` | `bool` |
| `datetime`, `date`, `time` | `DateTime` | `DateTime`, `DateTimeInterface` |
| `datetime_immutable`, `date_immutable`, `time_immutable` | `DateTimeImmutable` | `DateTimeImmutable` |
| `json` | `array` | `array` |
| `guid` | `string` | |
| backed enum | the enum | a `BackedEnum` (stored as its value) |

## Relations

| Attribute | Owning side | Options |
|---|---|---|
| `#[ORM\ManyToOne(Category::class)]` | always (column `category_id`) | `inversedBy`, `joinColumn`, `nullable` (true), `onDelete` (`CASCADE`, `SET NULL`), `cascade`, `fetch` (`lazy`, `eager`) |
| `#[ORM\OneToMany(Comment::class, mappedBy: 'post')]` | never: mapped by the `ManyToOne` of the target | `cascade`, `orphanRemoval`, `orderBy` |
| `#[ORM\OneToOne(Profile::class)]` | without `mappedBy` (unique column `profile_id`) | `mappedBy`, `inversedBy`, `joinColumn`, `nullable`, `onDelete`, `cascade`, `orphanRemoval` |
| `#[ORM\ManyToMany(Tag::class)]` | without `mappedBy` (join table `post_tag`) | `mappedBy`, `inversedBy`, `joinTable`, `joinColumn`, `inverseJoinColumn`, `cascade`, `orderBy` |

- Only the owning side is written to the database: update it (the `add...()` / `set...()` methods generated by `make:entity` do it).
- `cascade: ['persist']` persists the new related entities, `cascade: ['remove']` removes them with the entity, `'all'` does both. Without `persist` cascade, a new entity found through a relation throws an exception on `flush()`.
- `orphanRemoval: true` removes an entity removed from the collection (`OneToMany`) or replaced (`OneToOne`).
- Collections (`OneToMany`, `ManyToMany`) are typed `CollectionInterface`: an `ArrayCollection` for a new entity, a lazy `PersistentCollection` loaded on first use for an entity read from the database.
- `ManyToOne` and `OneToOne` relations are loaded lazily with a proxy (a generated subclass in `var/cache/orm/proxies`) that loads the entity on its first method call; `getId()` does not load it. A `final` class, or a class with `__get()`, is loaded immediately instead. An entity is unique per request: once a proxy exists for an id, `find()` and the queries return that same (initialized) proxy, so compare classes with `instanceof`, not with `$entity::class`.

## Inheritance

The mapped properties (`#[ORM\Column]`, `#[ORM\Id]`, relations), the lifecycle callbacks (even `private`) and the `#[ORM\Index]` of the parent classes are inherited. Mark a shared base class with `#[ORM\MappedSuperclass]`: it has no table and no repository, each entity extending it gets the columns in its own table.

```php
use NeoPHP\Package\Orm\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\Index(columns: ['createdAt'])]
abstract class AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    private function initCreatedAt(): void
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

#[ORM\Entity(repository: PostRepository::class)]
class Post extends AbstractEntity
{
    #[ORM\Column]
    private string $title = '';
}
```

- An abstract class cannot carry `#[ORM\Entity]` (`MappingException`): use `#[ORM\MappedSuperclass]`.
- A method overridden in the entity runs once (the overriding one); `private` callbacks of each class all run, parents first.
- The names of inherited indexes are ignored (generated per table) to avoid duplicated index names.
- An entity can extend another entity: the child has its own table with all the columns, queries on the parent never return children (no single table or joined inheritance).

## Collections

`NeoPHP\Package\Orm\Contract\CollectionInterface` (implemented by `NeoPHP\Package\Orm\Collection\ArrayCollection` and `PersistentCollection`) is `Countable`, `IteratorAggregate` and `ArrayAccess`:

| Method | Description |
|---|---|
| `add(mixed $element): static` | appends an element |
| `set(string\|int $key, mixed $value): static` / `get(string\|int $key): mixed` | writes / reads a key |
| `remove(string\|int $key): mixed` / `removeElement(mixed $element): bool` | removes by key / by value |
| `contains(mixed $element): bool` / `containsKey(string\|int $key): bool` / `indexOf(mixed $element): string\|int\|false` | searches |
| `getKeys()`, `getValues()`, `toArray()`, `first()`, `last()`, `isEmpty()`, `clear()` | access |
| `filter(callable $callback)`, `map(callable $callback)` | a new collection |
| `exists(callable $callback): bool`, `slice(int $offset, ?int $length = null): array` | queries |

`PersistentCollection` also exposes `getOwner()`, `getAssociation()`, `isInitialized()`, `initialize()`, `isDirty()` and `unwrap()` (the inner `ArrayCollection`).

## Persisting

```php
public function __construct(private EntityManagerInterface $entityManager)
{
}

$orm = $this->entityManager;

$post = (new Post())->setTitle('Hello')->setCategory($category);
$post->addTag($tag);

$orm->persist($post);
$orm->flush();

$post->setTitle('Hello world');
$orm->flush();

$orm->remove($post);
$orm->flush();
```

`flush()` computes the changes of every managed entity and writes them in one transaction: inserts (in the order of the relations), updates of the changed columns only, join tables, deletes.

| Method | Description |
|---|---|
| `persist($entity)` | manages a new entity (inserted on `flush()`) |
| `remove($entity)` | schedules the deletion |
| `flush()` | writes the changes |
| `find(Post::class, $id)` | the entity, or `null` |
| `getReference(Post::class, $id)` | a proxy, without query |
| `getRepository(Post::class)` | the repository of the entity |
| `createQueryBuilder()` / `createSqlQueryBuilder()` | the query builders |
| `refresh($entity)`, `detach($entity)`, `clear()`, `contains($entity)` | unit of work |
| `transactional(fn (OrmManagerInterface $orm) => ...)` | runs the callback and flushes in a transaction |

The same entity is returned for the same row (identity map). Inject `NeoPHP\Package\Orm\Contract\EntityManagerInterface` (or `OrmManagerInterface`, the same service) in a constructor or a controller action; a repository class (`PostRepository $posts`) can be injected the same way.

The `OrmManagerInterface` also gives access to the lower layers: `getConnection()`, `getPlatform()`, `getMetadata($class)`, `getMetadataFactory()`, `getUnitOfWork()`, `getProxyFactory()` and `getEventDispatcher()`.

### In a controller

```php
use NeoPHP\Package\Orm\Contract\EntityManagerInterface;

class PostController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/posts', name: 'post_index')]
    public function index(PostRepository $posts): Response
    {
        return $this->render('post/index', ['posts' => $posts->findAll()]);
    }

    #[Route('/posts/new', name: 'post_new', methods: ['POST'])]
    public function new(EntityManagerInterface $entityManager): Response
    {
        $entityManager->persist((new Post())->setTitle('Hello'));
        $entityManager->flush();

        return $this->redirectToRoute('post_index');
    }
}
```

The `OrmController` trait of `AbstractController` still provides `getOrm()` and `getRepository(string $entityClass)` for compatibility: prefer the injection.

## Repositories

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use App\Enum\PostStatus;
use NeoPHP\Package\Orm\Contract\AbstractRepository;

class PostRepository extends AbstractRepository
{
    protected string $entityClass = Post::class;

    public function findLatestPublished(int $limit = 10): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')->addSelect('c')
            ->where('p.status = :status')->setParameter('status', PostStatus::Published)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getResult();
    }
}
```

Repositories are services: inject them in controllers and services (`public function index(PostRepository $posts)`). An entity without repository gets a generic `EntityRepository`.

| Method | Returns |
|---|---|
| `find($id)` | an entity or `null` |
| `findAll($orderBy)` | all the entities |
| `findBy(['category' => $category, 'status' => [PostStatus::Draft, PostStatus::Published]], ['title' => 'ASC'], $limit, $offset)` | the matching entities; an array becomes `IN`, `null` becomes `IS NULL` |
| `findOneBy($criteria, $orderBy)` | the first matching entity or `null` |
| `count($criteria)` | the number of matching entities |
| `createQueryBuilder('p')` | a query builder selecting the entity |
| `save($entity, $flush = false)` / `delete($entity, $flush = false)` | `persist()` / `remove()`, then `flush()` if asked |
| `getEntityClass()`, `getOrm()`, `getMetadata()` | the entity class, the ORM, its metadata |

The keys of `$criteria` and `$orderBy` must be fields or associations of the entity, otherwise an `OrmException` is thrown: a sort column coming from the URL can be passed safely (`findBy([], [$request->query->getString('sort', 'id') => 'ASC'])`), catch the exception to fall back on a default order. The direction is always `ASC` or `DESC`.

## Query builder

The entity query builder uses properties (`p.publishedAt`) and relations (`p.category`); it translates them to columns and joins:

```php
$posts = $orm->createQueryBuilder()
    ->select('p', 'c')
    ->from(Post::class, 'p')
    ->leftJoin('p.category', 'c')
    ->join('p.tags', 't')
    ->where('t.name IN (:tags)')
    ->andWhere('p.category = :category')
    ->setParameter('tags', ['php', 'orm'])
    ->setParameter('category', $category)
    ->orderBy('p.title')
    ->setMaxResults(20)
    ->getResult();
```

| Method | Description |
|---|---|
| `select()` / `addSelect()` | an alias selects entities (a joined alias loads the relation in the same query and sets the real entities, not proxies), anything else is a scalar expression (`COUNT(p.id) AS total`) |
| `distinct()` | `SELECT DISTINCT` |
| `from(Post::class, 'p')` | the root entity (`getRootEntity()`, `getRootAlias()`) |
| `join()` / `innerJoin()` / `leftJoin()` | a relation (`'p.category'`), or an entity with a condition (`Category::class, 'c', 'c.id = p.category'`); an extra condition is added with `AND` |
| `where()` / `andWhere()` / `orWhere()`, `groupBy()` / `addGroupBy()`, `having()` / `andHaving()`, `orderBy()` / `addOrderBy()` | clauses; `p.category` is the foreign key column |
| `setParameter()` / `setParameters()` / `getParameters()` | named parameters; an entity becomes its id, an array is expanded for `IN` |
| `setMaxResults()` / `setFirstResult()` | limit and offset |
| `getResult()` | the entities (or rows `[entity, scalar...]` when scalars are selected too) |
| `getOneOrNullResult()` / `getSingleResult()` | one entity (`NonUniqueResultException`, `NoResultException`) |
| `getSingleScalarResult()`, `getScalarResult()`, `getArrayResult()` | a value, raw rows, entities as arrays |
| `getSQL()`, `getSqlQueryBuilder()` | the generated SQL, the underlying SQL query builder |

`createSqlQueryBuilder()` returns a SQL query builder working on tables and columns, usable without entities:

```php
$rows = $orm->createSqlQueryBuilder()
    ->select('c.name', 'COUNT(p.id) AS total')
    ->from('category', 'c')
    ->leftJoin('post', 'p', 'p.category_id = c.id')
    ->groupBy('c.name')
    ->fetchAllAssociative();

$orm->createSqlQueryBuilder()->update('post')->set('views', 'views + 1')->where('id = :id')->setParameter('id', 1)->executeStatement();
```

| Method | Description |
|---|---|
| `select()` / `addSelect()`, `distinct()`, `from($table, $alias)` | select queries |
| `insert($table)` + `values([...])` / `setValue()` | insert queries |
| `update($table, $alias)` + `set($column, $expression)` | update queries |
| `delete($table, $alias)` | delete queries |
| `join()` / `innerJoin()` / `leftJoin()` / `rightJoin()` | `($table, $alias, $condition)` |
| `where()` / `andWhere()` / `orWhere()`, `groupBy()` / `addGroupBy()`, `having()` / `andHaving()` / `orHaving()`, `orderBy()` / `addOrderBy()` | clauses |
| `setMaxResults()` / `getMaxResults()`, `setFirstResult()` / `getFirstResult()` | limit and offset |
| `setParameter()` / `setParameters()` / `getParameter()` / `getParameters()` | parameters |
| `executeQuery()` (a `Result`), `executeStatement()` (affected rows) | execution |
| `fetchAllAssociative()`, `fetchAssociative()`, `fetchOne()`, `fetchFirstColumn()` | fetch helpers |
| `getSQL()` / `__toString()`, `getType()`, `getConnection()` | inspection |

## Entities in controllers

An argument typed with an entity is loaded from the route parameters; a 404 error is thrown when it does not exist (`null` is passed instead when the argument is nullable):

```php
#[Route('/post/{id}', name: 'post_show')]
public function show(Post $post): Response                     // find({id})

#[Route('/post/{slug}', name: 'post_by_slug')]
public function bySlug(Post $post): Response                   // findOneBy(['slug' => {slug}])

#[Route('/user/{user}/post/{post}')]
public function userPost(User $user, Post $post): Response     // find({user}), find({post})

#[Route('/post/{id}/edit')]
public function edit(?Post $post): Response                    // null when not found
```

The route parameters are read in this order:

1. `#[MapEntity]` of the argument;
2. the parameter named like the argument (`{post}`), or `{post_id}` / `{postId}`: the identifier;
3. `{id}`: the identifier, only when the action has a single entity argument;
4. the other route parameters that are fields of the entity (`{slug}`, `{email}`...): `findOneBy()`.

When the route gives none of them, the argument is resolved as before (the container builds an empty entity): use `#[MapEntity]` or type the argument with `int $id`.

```php
use NeoPHP\Package\Orm\Attribute\MapEntity;

#[Route('/blog/{category}/{slug}')]
public function show(
    #[MapEntity(mapping: ['category' => 'slug'])] Category $category,
    #[MapEntity(mapping: ['slug' => 'slug', 'category' => 'category'], message: 'Article introuvable.')] Post $post,
): Response

#[Route('/comment/{comment_id}')]
public function comment(#[MapEntity(id: 'comment_id')] Comment $comment): Response

public function create(#[MapEntity(disabled: true)] Post $post): Response   // never converted
```

| `#[MapEntity]` option | Description |
|---|---|
| `id` | route parameter holding the identifier |
| `mapping` | route parameter => field (or association) of the entity, searched with `findOneBy()` |
| `disabled` | `true`: the argument is not converted |
| `message` | message of the 404 error |

The conversion is done by `NeoPHP\Package\Orm\ArgumentResolver\EntityValueResolver`, registered as a controller argument resolver.

## Lifecycle callbacks and events

```php
#[ORM\PrePersist]
public function onPrePersist(): void
{
    $this->createdAt = new DateTimeImmutable();
}

#[ORM\PreUpdate]
public function onPreUpdate(PreUpdateEvent $event): void
{
    if ($event->hasChangedField('title')) {
        $this->updatedAt = new DateTimeImmutable();
    }
}
```

| Callback attribute | Event (`NeoPHP\Package\Orm\Event\`) | When |
|---|---|---|
| `#[ORM\PrePersist]` | `PrePersistEvent` | on `persist()` |
| `#[ORM\PostPersist]` | `PostPersistEvent` | after the insert |
| `#[ORM\PreUpdate]` | `PreUpdateEvent` (`getChangeSet()`, `hasChangedField()`, `getOldValue()`, `getNewValue()`) | before the update; the changes made in the callback are saved |
| `#[ORM\PostUpdate]` | `PostUpdateEvent` | after the update |
| `#[ORM\PreRemove]` | `PreRemoveEvent` | on `remove()` |
| `#[ORM\PostRemove]` | `PostRemoveEvent` | after the delete |
| `#[ORM\PostLoad]` | `PostLoadEvent` | after the entity is loaded |
| | `PreFlushEvent`, `PostFlushEvent` | around `flush()` |

Every event extends `LifecycleEvent` (`getEntity()`, `getOrm()`), except `PreFlushEvent` and `PostFlushEvent` which extend `FlushEvent` (`getOrm()`). The events are dispatched with the event dispatcher: a listener receives them like any other event (see the Events documentation).

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Package\Orm\Event\PrePersistEvent;

class TimestampListener
{
    #[AsListener]
    public function onPrePersist(PrePersistEvent $event): void
    {
        $entity = $event->getEntity();

        if (method_exists($entity, 'setCreatedAt')) {
            $entity->setCreatedAt(new \DateTimeImmutable());
        }
    }
}
```

## Forms

The `entity` form type (`NeoPHP\Package\Orm\Helper\Form\EntityType`, a `ChoiceType`) lists entities as choices (see the Forms documentation):

```php
$builder->add('category', EntityType::class, [
    'class' => Category::class,
    'query_builder' => fn (CategoryRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')->orderBy('c.name'),
]);
```

| Option | Description |
|---|---|
| `class` | the entity class (required) |
| `query_builder` | a `QueryBuilder`, or a callable receiving the repository and returning one; `findAll()` when empty |
| `choices` | an explicit array of entities |

## Generating code

`make:entity` without fields starts a wizard. It creates the entity, or completes it when it already exists (the new properties and methods are added to its class, the rest of the file is kept):

```
$ php bin/neo make:entity
 Class name of the entity to create or update (e.g. BlogPost) (? to list):
 > Comment

 New property name (press <return> to stop adding fields):
 > content
 Field type (enter ? to see all types) [text]:
 >
 Can this field be null in the database (nullable) (yes/no) [no]:
 >

 New property name (press <return> to stop adding fields):
 > post
 Field type (enter ? to see all types) [ManyToOne]:
 >
 What class should this entity be related to? [Post] (? to list):
 >
 Is the Comment.post property allowed to be null (nullable) (yes/no) [yes]:
 > no
 Do you want to add a new property to Post so that you can access/update Comment objects from it - e.g. $post->getComments() (yes/no) [yes]:
 >
 New field name inside Post [comments]:
 >
 Do you want to delete the orphaned Comment objects (orphanRemoval)? ... (yes/no) [no]:
 > yes

 New property name (press <return> to stop adding fields):
 >
```

- **Types**: `?` lists them (`string`, `text`, `integer`, `decimal`, `boolean`, dates, `json`, `uuid`, `enum`, `relation` and the four relation types). The default type is guessed from the name: `email` → `string` 180 unique, `slug` → unique, `createdAt` → `datetime_immutable`, `publishedAt` → nullable datetime, `isActive` / `published` → `boolean`, `price` → `decimal`, `description` → `text`, `position` → `integer`, `roles` → `json`, and a name matching an entity (`category` → `Category`, `tags` → `Tag`) → a relation.
- **Questions per type**: length for `string`, precision and scale for `decimal`, the enum (from `src/Enum`) for `enum`, unique for strings and integers, and nullable.
- **Relations**: `relation` explains the four types with the real class names. The inverse side is proposed and written in the target entity (`Post::$comments` with `getComments()`, `addComment()` and `removeComment()`, which keep both sides in sync). `OneToMany` always adds the `ManyToOne` side in the target. Self-references (`Category::$children` / `$parent`) are supported.
- **Checks**: an existing property or method, or a name used twice, is refused before anything is written; a refused field is not added and the wizard goes on.

With fields on the command line, nothing is asked (useful in scripts), and the fields are added to the entity if it exists:

```bash
php bin/neo make:entity Category name:string:100 posts:OneToMany:Post:category
php bin/neo make:entity Post title:string:120 content:text? status:enum:App\\Enum\\PostStatus publishedAt:datetime_immutable? category:ManyToOne:Category tags:ManyToMany:Tag
php bin/neo make:repository Post
```

A field is `name:type`; a trailing `?` makes it nullable. Types: `string[:length]`, `text`, `integer`, `smallint`, `bigint`, `float`, `decimal[:precision[:scale]]`, `boolean`, `datetime`, `datetime_immutable`, `date`, `date_immutable`, `time`, `json`, `guid`, `enum:Class`, and the relations `ManyToOne:Target`, `OneToOne:Target`, `OneToMany:Target[:mappedBy]`, `ManyToMany:Target`. On the command line, the inverse side is not generated. `--force` regenerates an existing entity from scratch (its repository is kept). An entity in a sub-namespace (`Blog/Post`) gets its repository in the same sub-namespace (`App\Repository\Blog\PostRepository`).

## Migrations

```bash
php bin/neo make:migration --description="Blog schema"
php bin/neo migration:migrate
php bin/neo migration:status
php bin/neo migration:rollback
```

`make:migration` compares the entities to the database (tables, columns, indexes, foreign keys) and writes `migrations/Migration_{hash}.php` with the SQL of the database in use. The hash starts with the creation time, so migrations run in the order they were generated. The optional description is asked only when there are changes (press Enter to skip), unless `--description` is given.

```php
<?php

declare(strict_types=1);

namespace Migrations;

use NeoPHP\Package\Orm\Contract\AbstractMigration;

class Migration_01a0d70c0b0beeb3 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blog schema';
    }

    public function up(): void
    {
        $this->abortIf($this->getPlatformName() !== 'mysql', 'This migration was generated for mysql.');

        $this->addSql('CREATE TABLE `category` (...)');
    }

    public function down(): void
    {
        $this->abortIf($this->getPlatformName() !== 'mysql', 'This migration was generated for mysql.');

        $this->addSql('DROP TABLE `category`');
    }
}
```

- `make:migration` refuses to run while migrations are pending, and does nothing when the database is in sync. `--empty` creates an empty migration to write by hand.
- The executed migrations are stored in the `neo_migrations` table. Each migration runs in a transaction on PostgreSQL and SQLite (MySQL commits DDL statements immediately).
- On SQLite, a changed table is rebuilt (new table, copy of the data, rename), with the foreign keys disabled during the migration.
- The generated SQL can be edited before `migration:migrate`; `$this->connection` is available for data migrations.

### NeoPHP packages

A NeoPHP package installed with Composer (see the Package documentation) can ship entities and migrations:

| In the package | Default | Description |
|---|---|---|
| `extra.neophp.entities` | `src/Entity` | entities mapped by the ORM with the entities of the project |
| `extra.neophp.migrations` | `migrations` | migrations executed by `migration:migrate` with the migrations of the project, in the order of their version |
| `extra.neophp.migrations_namespace` | namespace of the module + `\Migrations` | namespace of the migration classes |

- The tables of the package entities belong to the package: `make:migration` of the project never creates, changes nor drops them.
- `make:migration --package=billing` generates a migration of the package from its entities, in its `migrations/` directory (development of a package in a project with a Composer path repository).
- `migration:status` shows the source of each migration (`app` or the Composer name of the package).
- The migrations of a disabled package are not executed.

`AbstractMigration` (implements `MigrationInterface`):

| Method | Description |
|---|---|
| `up(): void` / `down(): void` | apply / revert the migration |
| `getDescription(): string` | the description shown by `migration:status` |
| `getVersion(): string` | the version (the class name without `Migration_`) |
| `addSql(string $sql, array $params = [])` | adds a statement (protected) |
| `abortIf(bool $condition, string $message)` | stops the migration (protected) |
| `getPlatformName(): string` | `mysql`, `pgsql` or `sqlite` |
| `isTransactional(): bool` | whether it runs in a transaction |
| `getSql()` / `clearSql()` | the collected statements |

## Commands

| Command | Arguments and options |
|---|---|
| `make:entity` | `name` (e.g. `Post`, `Blog/Post`), `fields...`; global `--force` regenerates the entity |
| `make:repository` | `entity` |
| `make:migration` | `--empty`, `--description`/`-d`, `--package`/`-p` |
| `migration:migrate` (alias `migrate`) | `--dry-run` shows the SQL without executing it |
| `migration:rollback` (alias `rollback`) | `--steps`/`-s` (1), `--dry-run` |
| `migration:status` | lists the migrations, their source and whether they are executed |

## Exceptions

All in `NeoPHP\Package\Orm\Exception\`, extending `OrmException` (itself a `FrameworkException`):

| Exception | Thrown when |
|---|---|
| `MappingException` | an entity is badly mapped |
| `EntityNotFoundException` | a proxy cannot load its entity |
| `NoResultException` | `getSingleResult()` finds nothing |
| `NonUniqueResultException` | `getOneOrNullResult()` / `getSingleResult()` find several entities |
| `MigrationException` | a migration fails or is aborted |

## Limits

Composite identifiers, single table / joined inheritance, readonly properties and changes of the primary key are not supported. The ORM should be cleared (`clear()`) after a failed `flush()`.

## Profiler

The unit of work keeps cheap counters, read with `$orm->getUnitOfWork()->getStatistics()`: managed entities per class (identity map), pending inserts and removals, flushes (inserts, updates, deletes, collection updates and duration of the last 100 flushes) and initialized proxies.

When the Web Profiler is enabled, the `Helper/WebProfiler/OrmProfiler` element adds an ORM item to the toolbar (number of managed entities, shown only when the ORM was used) and a panel with the managed entities per class, the flushes and the unit of work state. SQL queries are shown by the Database panel.

## Changelog

- v2.1.0 — Entities and migrations of the NeoPHP packages: package entities mapped, package tables ignored by `make:migration`, package migrations executed with the project ones, `make:migration --package`, source column in `migration:status`; `Migrator::addSource()`, `getSources()`, `getSource()`, `SchemaTool::forMetadata()`.
- v2.0.0 — `OrmManager` is the `final` entry point of the module, declared with `#[Package]`; `OrmManagerInterface` replaces `Contract\OrmInterface`; `Contract\AbstractOrm` is merged into the manager; `Helper/Profiler` is renamed `Helper/WebProfiler`; the internal classes are marked `@internal`.
- v1.35.0 — `#[ORM\MappedSuperclass]` and inherited mapping (parent callbacks, even private, and indexes), abstract entities refused; `EntityManagerInterface` injectable in constructors and controller actions (alias of `OrmInterface`, service `entity_manager`).
- v1.31.0 — `make:migration` ignores the framework tables `cache_items` and `remember_me_tokens`; entities in controller arguments (`EntityValueResolver`, `#[MapEntity]`), 404 when not found.
- v1.30.0 — `findBy()`, `findOneBy()`, `count()` and `findAll()` refuse the criteria and order keys that are not fields or associations of the entity (SQL injection through a user-controlled key).
- v1.25.1 — profiler integration: `UnitOfWork::getStatistics()` (managed entities, flushes, initialized proxies) and ORM panel of the Web Profiler.
- v1.17.0 — `make:entity` wizard (fields asked one by one, guessed types, relations with their inverse side written in the target entity, completion of existing entities, checks before writing), sub-namespace repositories; `make:*` commands ask for their values. Bugfix: `make:migration` asks the optional description only when there are changes.
- v1.15.0 — ORM commands rewritten as `AbstractConsole` commands.
- v1.12.0 — `entity` form type.
- v1.11.0 — ORM package: entities mapped with attributes, `ManyToOne` / `OneToMany` / `OneToOne` / `ManyToMany` relations with lazy loading (generated proxies, lazy collections), cascade and orphan removal, unit of work with identity map and change tracking, repositories, entity and SQL query builders, lifecycle callbacks and events, enum / JSON / date / decimal types, `make:entity`, `make:repository`, `make:migration`, `migration:migrate`, `migration:rollback` and `migration:status` commands, `config/packages/orm.yaml`.