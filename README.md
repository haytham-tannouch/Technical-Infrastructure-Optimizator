# Optimisation de l'infrastructure

Cette application en ligne de commande analyse un rapport contenant des métriques d'infrastructure, détecte les anomalies et génère un rapport JSON avec des recommandations d'optimisation.

## 1. Besoin

Le fichier d'entrée contient une liste de mesures techniques au format JSON : utilisation du CPU et de la mémoire, latence, stockage, réseau, taux d'erreur, température, consommation électrique et statut des services.

L'application doit :

1. Charger et valider le fichier d'entrée.
2. Calculer les indicateurs globaux du rapport.
3. Détecter les métriques qui dépassent les seuils configurés.
4. Attribuer une sévérité à chaque anomalie.
5. Résumer le statut des services.
6. Utiliser un LLM pour enrichir les anomalies avec des descriptions et produire des recommandations d'optimisation.
7. Générer un fichier `output.json` conforme au format demandé.

Le rapport final contient un timestamp, les indicateurs calculés, les anomalies détectées, les recommandations et le résumé des statuts de services.

## 2. Choix techniques

### PHP 8.3

Le projet utilise PHP 8.3 avec une architecture simple, sans framework. Le besoin correspond à un traitement CLI linéaire avec peu de dépendances. Ajouter un framework complet aurait apporté plus de configuration que de valeur pour ce périmètre.

### Analyse déterministe

Les calculs, les dépassements de seuils et les niveaux de sévérité sont déterminés par le code PHP. Le LLM ne calcule pas les valeurs et ne décide pas si une métrique est anormale.

Cette séparation permet de garder les résultats mesurables et reproductibles. Le LLM intervient uniquement sur les tâches qui nécessitent une interprétation textuelle :

- rédaction d'une description pour chaque anomalie ;
- génération de recommandations techniques ;
- regroupement des problèmes qui peuvent être traités par une même action.

### OpenAI Responses API

La communication avec le LLM passe directement par l'API HTTP avec cURL. Ce choix limite les dépendances et garde la gestion de la requête, des erreurs HTTP et des timeouts explicite dans le code.

Le format de la réponse est contrôlé avec un JSON Schema strict. Le code vérifie ensuite que chaque anomalie possède exactement une description avant de générer le rapport final.

### Configuration centralisée

Le fichier `config.php` contient :

- les chemins par défaut des fichiers d'entrée et de sortie ;
- la configuration OpenAI ;
- les seuils `low`, `medium` et `high` pour chaque métrique.

La clé API et le modèle sont chargés depuis un fichier `.env` avec `vlucas/phpdotenv`. Les informations sensibles ne sont pas stockées dans le code ni suivies par Git.

## 3. Architecture

L'application est organisée sous forme d'un pipeline composé de quatre étapes indépendantes :

```text
rapport.json
    -> ingestion
    -> analyse
    -> enrichissement LLM
    -> génération du rapport
    -> output.json
```

### `optimize.php`

Point d'entrée de l'application. Il charge la configuration, récupère les chemins passés en arguments et exécute les étapes dans l'ordre. Les erreurs sont interceptées et retournées dans la sortie d'erreur avec un code de sortie non nul.

### `src/ingest.php`

Charge le fichier JSON et vérifie sa structure avant tout traitement :

- présence et lisibilité du fichier ;
- validité du JSON ;
- présence des métriques attendues ;
- validité du timestamp ;
- validité des statuts de services.

### `src/analyze.php`

Parcourt toutes les mesures et produit une analyse déterministe :

- moyenne de la latence et du taux d'erreur ;
- valeurs maximales du CPU, de la mémoire et de l'uptime ;
- détection des dépassements de seuils ;
- niveau de sévérité le plus élevé par métrique ;
- nombre de dépassements par métrique ;
- pire statut observé pour chaque service.

Les occurrences et le nombre total de mesures sont transmis au LLM pour fournir le contexte nécessaire. Ces champs sont internes au pipeline et ne sont pas ajoutés au format final demandé.

### `src/llm.php`

Envoie l'analyse au LLM et récupère :

- une description pour chaque anomalie ;
- une liste de recommandations structurées.

Le nœud gère la configuration, les erreurs réseau, les erreurs retournées par l'API, les refus, le décodage JSON et la validation des descriptions.

### `src/output.php`

Assemble le rapport final. Les descriptions sont associées aux anomalies par le nom de la métrique et non par leur position dans le tableau.

Le fichier produit respecte la structure suivante :

```text
timestamp
insights
anomalies
recommendations
service_status_summary
```

## 4. Installation, exécution et validation

### Prérequis

- PHP 8.3 ou supérieur ;
- Composer ;
- extension PHP cURL ;
- une clé API OpenAI ;
- un modèle OpenAI compatible avec Structured Outputs.

Vérifier la version de PHP et l'extension cURL :

```bash
php -v
php -m | grep curl
```

### Installation

Installer les dépendances :

```bash
composer install
```

Créer le fichier de configuration local :

```bash
cp .env.example .env
```

Renseigner ensuite les variables dans `.env` :

```dotenv
OPENAI_API_KEY=your-api-key
OPENAI_MODEL=your-model
```

### Générer le rapport

Avec les chemins par défaut :

```bash
composer optimize
```

Cette commande lit `rapport.json` et génère `output.json` à la racine du projet.

Pour utiliser des chemins personnalisés :

```bash
composer optimize -- chemin/rapport.json chemin/output.json
```

La commande peut aussi être exécutée directement avec PHP :

```bash
php optimize.php chemin/rapport.json chemin/output.json
```

Afficher l'aide :

```bash
php optimize.php --help
```

### Vérifier le projet

Valider la configuration Composer :

```bash
composer validate --no-check-publish
```

Vérifier la syntaxe des fichiers PHP :

```bash
php -l optimize.php
php -l config.php
for file in src/*.php; do php -l "$file"; done
```

Exécuter le pipeline complet :

```bash
composer optimize
```

Vérifier que le fichier généré contient un JSON valide :

```bash
php -r 'json_decode(file_get_contents("output.json"), true, 512, JSON_THROW_ON_ERROR); echo "output.json is valid\n";'
```

En cas d'erreur, la commande affiche le message dans `STDERR` et retourne un code de sortie `1`. Si le traitement se termine correctement, le chemin du rapport généré est affiché dans le terminal.
