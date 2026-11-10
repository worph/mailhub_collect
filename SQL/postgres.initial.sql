-- mailhub_collect: where each collected address was found.
CREATE TABLE mailhub_collect_origin (
  user_id integer NOT NULL
    REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  email varchar(255) NOT NULL,
  kind varchar(16) NOT NULL,
  folder varchar(255) NOT NULL DEFAULT '',
  hits integer NOT NULL DEFAULT 0,
  first_seen timestamp with time zone NOT NULL,
  last_seen timestamp with time zone NOT NULL,
  PRIMARY KEY (user_id, email, kind, folder)
);

INSERT INTO "system" (name, value) VALUES ('mailhub_collect-version', '2026111000');
