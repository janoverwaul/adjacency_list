<?php
// +---------------------------------------------------------------------+
// | Klasse ADJACENCY_LIST                                               |
// | Baumstruktur über Adjacency List mit rekursiven CTEs (MySQL 8+)     |
// +---------------------------------------------------------------------+
// | Verschieben, Einfügen und Löschen ohne Massenupdate aller Knoten.   |
// +---------------------------------------------------------------------+
// | Basierend auf:                                                      |
// | public const VERSION  = '0.9';                                      |
// | public const AUTOR    = 'Oliver Blum';                              |
// | public const PACKAGE  = 'Blumithek';                                |
// | public const LAST_CH  = '05.09.2007';                               |
// | public const KLASSE   = 'NESTEDSET';                                |
// +---------------------------------------------------------------------+

declare(strict_types=1);

class ADJACENCY_LIST {

    public const VERSION  = '1.0';
    public const AUTOR    = 'Jan Overwaul + claude.ai';
    public const LAST_CH  = '2026';
    public const KLASSE   = 'ADJACENCY_LIST';

    protected PDO $pdo;

    /**
     * Konstruktor: Baut die PDO-Datenbankverbindung auf.
     *
     * @param string $host     Hostname (z.B. 'localhost')
     * @param string $dbname   Datenbankname
     * @param string $user     Datenbankbenutzer
     * @param string $password Passwort
     * @param string $charset  Zeichensatz (Standard: utf8mb4)
     * @throws PDOException bei Verbindungsfehler
     */
    public function __construct(
        string $host,
        string $dbname,
        string $user,
        string $password,
        string $charset = 'utf8mb4'
    ) {
        $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $this->pdo = new PDO($dsn, $user, $password, $options);
    }

    // -------------------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------------------

	/**
	 * Pflichtfelder, die in jeder verwalteten Tabelle vorhanden sein müssen.
	 */
	private const REQUIRED_COLUMNS = ['id', 'name', 'parent_id', 'sort_order', 'online'];

	/**
	 * Stellt sicher, dass die Tabelle existiert und das korrekte Schema hat.
	 * - Tabelle fehlt     → wird automatisch angelegt
	 * - Tabelle vorhanden → Schema wird geprüft; bei Abweichung Exception
	 *
	 * @param string $sql_table Tabellenname
	 * @return true
	 * @throws RuntimeException wenn das Schema nicht passt
	 */
	protected function ensure_table(string $sql_table): true {
		// MariaDB-kompatibel: kein Prepared Statement für SHOW TABLES
		$escaped = $this->pdo->quote($sql_table);
		$stmt    = $this->pdo->query("SHOW TABLES LIKE {$escaped}");

		if ($stmt->rowCount() === 0) {
			// Tabelle anlegen
			$this->pdo->exec("
				CREATE TABLE `{$sql_table}` (
					`id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`name`       VARCHAR(255) NOT NULL,
					`parent_id`  INT UNSIGNED NOT NULL DEFAULT 0,
					`sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
					`online`     TINYINT(1)   NOT NULL DEFAULT 1,
					INDEX `idx_parent` (`parent_id`),
					INDEX `idx_sort`   (`parent_id`, `sort_order`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
			");
			return true;
		}

		// Tabelle vorhanden → Spalten prüfen
		$stmt = $this->pdo->query("SHOW COLUMNS FROM `{$sql_table}`");
		$existing = array_column($stmt->fetchAll(), 'Field');
		$missing  = array_diff(self::REQUIRED_COLUMNS, $existing);

		if (!empty($missing)) {
			throw new RuntimeException(
				"Tabelle '{$sql_table}': Schema ungültig. "
			  . "Fehlende Spalten: " . implode(', ', $missing)
			);
		}

		return true;
	}

    /**
     * Holt alle Informationen zu einer bestimmten ID.
     *
     * @param string $sql_table Tabellenname
     * @param int|null $link_id ID des Knotens
     * @return array|false
     */
	protected function get_link_info(string $sql_table, ?int $link_id): array {
		if (!$link_id) {
			throw new InvalidArgumentException("Ungültige ID: null oder 0 übergeben.");
		}
		$stmt = $this->pdo->prepare("SELECT * FROM `{$sql_table}` WHERE id = ?");
		$stmt->execute([$link_id]);
		$result = $stmt->fetchAll();

		if (empty($result)) {
			throw new RuntimeException("Knoten mit ID {$link_id} nicht gefunden in Tabelle '{$sql_table}'.");
		}
		return $result;
	}

    /**
     * Ermittelt die Sortierposition (sort_order) für einen neuen Knoten
     * als letztes Kind des Elternknotens.
     *
     * @param string $sql_table Tabellenname
     * @param int $parent_id    Eltern-ID
     * @return int
     */
    protected function get_next_sort_order(string $sql_table, int $parent_id): int {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_pos
               FROM `{$sql_table}`
              WHERE parent_id = ?"
        );
        $stmt->execute([$parent_id]);
        return (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Öffentliche Methoden
    // -------------------------------------------------------------------------

    /**
     * Holt alle Knoten unterhalb einer bestimmten ID (inkl. sich selbst).
     * Gibt Tiefe (LEVEL), Kinderzahl (KINDER) und ob Kinder vorhanden (KONTROLLE) zurück –
     * analog zur NESTEDSET-Klasse.
     *
     * @param int    $meng_num   ID des Wurzelknotens
     * @param string $sql_table  Tabellenname
     * @param string|null $secure 'secure' = nur online=1 Einträge
     * @return array|false
     */
    public function get_menge(int $meng_num, string $sql_table, ?string $secure = null): array|false {
        $this->ensure_table($sql_table);
		$this->get_link_info($sql_table, $meng_num);

        $secureFilter = ($secure === 'secure') ? 'AND n.online = 1' : '';

        // Rekursive CTE: Baum ab $meng_num auffalten
        $sql = "
            WITH RECURSIVE baum AS (
                -- Anker: Startknoten
                SELECT
                    n.*,
                    0 AS LEVEL
                FROM `{$sql_table}` n
                WHERE n.id = :start_id
                  {$secureFilter}

                UNION ALL

                -- Rekursion: alle direkten Kinder
                SELECT
                    n.*,
                    b.LEVEL + 1
                FROM `{$sql_table}` n
                INNER JOIN baum b ON n.parent_id = b.id
                {$secureFilter}
            )
            SELECT
                b.*,
                -- Anzahl direkter Kinder
                (SELECT COUNT(*) FROM `{$sql_table}` c WHERE c.parent_id = b.id) AS KINDER,
                -- 'auf' = hat Kinder, 'zu' = Blattknoten
                IF(
                    (SELECT COUNT(*) FROM `{$sql_table}` c WHERE c.parent_id = b.id) > 0,
                    'auf', 'zu'
                ) AS KONTROLLE,
                -- Hat nachfolgenden Geschwisterknoten?
                EXISTS (
                    SELECT 1 FROM `{$sql_table}` s
                     WHERE s.parent_id = b.parent_id
                       AND s.sort_order > b.sort_order
                ) AS GROESSER
            FROM baum b
            ORDER BY b.LEVEL, b.sort_order
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start_id' => $meng_num]);
        $result = $stmt->fetchAll();
        return (!empty($result)) ? $result : false;
    }

    /**
     * Fügt einen neuen Knoten als letztes Kind des Elternknotens ein.
     *
     * @param string   $link_name  Name des Knotens
     * @param string   $sql_table  Tabellenname
     * @param int|null $parent_id  Eltern-ID (null = Root-Ebene, parent_id = 0)
     * @return bool
     */
	public function insert_knoten(string $link_name, string $sql_table, ?int $parent_id = null): bool {
		$this->ensure_table($sql_table);

		$parent_id = $parent_id ?? 0;

		if ($parent_id === 0) {
			// Nur einen Root-Knoten erlauben
			$stmt = $this->pdo->prepare(
				"SELECT COUNT(*) FROM `{$sql_table}` WHERE parent_id = 0"
			);
			$stmt->execute();
			if ((int)$stmt->fetchColumn() > 0) {
				throw new RuntimeException(
					"Es existiert bereits ein Root-Knoten (parent_id = 0). Parallele Bäume sind nicht erlaubt."
				);
			}
		} else {
			// Elternknoten muss existieren
			$this->get_link_info($sql_table, $parent_id); // wirft RuntimeException wenn nicht gefunden
		}

		$sort_order = $this->get_next_sort_order($sql_table, $parent_id);

		$stmt = $this->pdo->prepare(
			"INSERT INTO `{$sql_table}` (name, parent_id, sort_order)
			 VALUES (:name, :parent_id, :sort_order)"
		);
		return $stmt->execute([
			':name'       => $link_name,
			':parent_id'  => $parent_id,
			':sort_order' => $sort_order,
		]);
	}

    /**
     * Löscht einen Knoten und rekursiv alle seine Unterknoten.
     *
     * @param int    $site_id    ID des zu löschenden Knotens
     * @param string $sql_table  Tabellenname
     * @return bool
     */
    public function del_knoten(int $site_id, string $sql_table): bool {
        $this->ensure_table($sql_table);

        $info = $this->get_link_info($sql_table, $site_id);
        if (!$info) {
            return false;
        }

        // Alle Nachfahren-IDs per rekursiver CTE ermitteln, dann löschen
        $sql = "
            WITH RECURSIVE nachfahren AS (
                SELECT id FROM `{$sql_table}` WHERE id = :start_id
                UNION ALL
                SELECT n.id FROM `{$sql_table}` n
                INNER JOIN nachfahren nd ON n.parent_id = nd.id
            )
            DELETE FROM `{$sql_table}`
            WHERE id IN (SELECT id FROM nachfahren)
        ";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':start_id' => $site_id]);
    }

    /**
     * Verschiebt einen Knoten nach oben oder unten innerhalb seiner Geschwister.
     * Tauscht sort_order mit dem jeweiligen Nachbar-Geschwisterknoten.
     *
     * @param int    $site_id    ID des Knotens
     * @param string $direction  'links' = nach oben, 'rechts' = nach unten
     * @param string $sql_table  Tabellenname
     * @return bool
     */
    public function reorder_knoten(int $site_id, string $direction, string $sql_table): bool {
        $this->ensure_table($sql_table);

        $info = $this->get_link_info($sql_table, $site_id);
        if (!$info) {
            return false;
        }

        $parent_id  = (int)$info[0]['parent_id'];
        $sort_order = (int)$info[0]['sort_order'];

        // Geschwisterknoten suchen
        if ($direction === 'links') {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM `{$sql_table}`
                  WHERE parent_id = :parent_id AND sort_order < :sort_order
                  ORDER BY sort_order DESC LIMIT 1"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM `{$sql_table}`
                  WHERE parent_id = :parent_id AND sort_order > :sort_order
                  ORDER BY sort_order ASC LIMIT 1"
            );
        }

        $stmt->execute([':parent_id' => $parent_id, ':sort_order' => $sort_order]);
        $sibling = $stmt->fetch();

		if (!$sibling) {
			throw new RuntimeException(
				"Knoten ID {$site_id} kann nicht nach '{$direction}' verschoben werden: kein Geschwister vorhanden."
			);
		}

        // sort_order tauschen
        $this->pdo->beginTransaction();
        try {
            $upd1 = $this->pdo->prepare(
                "UPDATE `{$sql_table}` SET sort_order = :new_order WHERE id = :id"
            );
            $upd1->execute([':new_order' => $sibling['sort_order'], ':id' => $site_id]);

            $upd2 = $this->pdo->prepare(
                "UPDATE `{$sql_table}` SET sort_order = :new_order WHERE id = :id"
            );
            $upd2->execute([':new_order' => $sort_order, ':id' => $sibling['id']]);

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    /**
     * Verschiebt einen Knoten (inkl. aller Unterknoten) zu einem neuen Elternknoten.
     * In NESTEDSET nicht implementiert ("coming soon") – hier vollständig umgesetzt.
     *
     * @param int    $site_id       ID des zu verschiebenden Knotens
     * @param int    $new_parent_id ID des neuen Elternknotens
     * @param string $sql_table     Tabellenname
     * @return bool
     * @throws InvalidArgumentException wenn Ziel ein Nachfahre der Quelle ist
     */
    public function move_knoten(int $site_id, int $new_parent_id, string $sql_table): bool {
        $this->ensure_table($sql_table);

		if ($site_id === $new_parent_id) {
			throw new InvalidArgumentException("Ein Knoten kann nicht sein eigener Elternknoten sein.");
		}

        // Sicherstellen, dass der Zielknoten kein Nachfahre des Quellknotens ist
        $descendants = $this->get_menge($site_id, $sql_table);
        if ($descendants) {
            $ids = array_column($descendants, 'id');
            if (in_array($new_parent_id, $ids, true)) {
                throw new InvalidArgumentException(
                    "Zielknoten (ID {$new_parent_id}) ist ein Nachfahre des zu verschiebenden Knotens."
                );
            }
        }

        $sort_order = $this->get_next_sort_order($sql_table, $new_parent_id);

        $stmt = $this->pdo->prepare(
            "UPDATE `{$sql_table}`
                SET parent_id  = :new_parent_id,
                    sort_order = :sort_order
              WHERE id = :id"
        );

        return $stmt->execute([
            ':new_parent_id' => $new_parent_id,
            ':sort_order'    => $sort_order,
            ':id'            => $site_id,
        ]);
    }

	/**
	 * Benennt einen Knoten um.
	 *
	 * @param int    $site_id    ID des Knotens
	 * @param string $new_name   Neuer Name
	 * @param string $sql_table  Tabellenname
	 * @return bool
	 */
	public function rename_knoten(int $site_id, string $new_name, string $sql_table): bool {
		$this->ensure_table($sql_table);
		$this->get_link_info($sql_table, $site_id); // wirft Exception wenn nicht gefunden

		$new_name = trim($new_name);
		if ($new_name === '') {
			throw new InvalidArgumentException("Name darf nicht leer sein.");
		}

		$stmt = $this->pdo->prepare(
			"UPDATE `{$sql_table}` SET name = :name WHERE id = :id"
		);
		return $stmt->execute([':name' => $new_name, ':id' => $site_id]);
	}
}

