CREATE ROLE "info.announcements" LOGIN PASSWORD 'password';

CREATE TABLE announcements(
    annoid serial not null primary key,
    time timestamptz not null default current_timestamp,
    path varchar(255) not null,
    title varchar(255) not null,
    excerpt text not null,
    feature_img varchar(255),
    body text,
    enabled boolean not null
);

GRANT SELECT, INSERT, UPDATE, DELETE ON announcements TO "info.announcements";
GRANT SELECT, USAGE ON announcements_annoid_seq TO "info.announcements";
