// Package notifications ports the operator-facing, per-recipient notification
// centre. Every read and mutation is constrained by recipient_user_id.
package notifications

import (
	"context"
	"database/sql"
	"errors"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

var ErrNotFound = errors.New("notification not found")

type Store struct{ database *db.DB }

func New(database *db.DB) *Store { return &Store{database: database} }

type Item struct {
	ID        int64      `json:"id"`
	Type      string     `json:"type"`
	Severity  string     `json:"severity"`
	Title     string     `json:"title"`
	Body      string     `json:"body"`
	OrderID   *int64     `json:"order_id"`
	ReadAt    *time.Time `json:"read_at"`
	CreatedAt time.Time  `json:"created_at"`
}

type Page struct {
	Items       []Item
	UnreadCount int
	Total       int
	CurrentPage int
	LastPage    int
}

func (s *Store) List(ctx context.Context, userID int64, unread bool, page, perPage int) (*Page, error) {
	if page < 1 {
		page = 1
	}
	if perPage < 1 || perPage > 50 {
		perPage = 20
	}
	out := &Page{Items: []Item{}, CurrentPage: page, LastPage: 1}
	var err error
	if err = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_notifications WHERE recipient_user_id=? AND read_at IS NULL`, userID).Scan(&out.UnreadCount); err != nil {
		if db.IsMissingSchema(err) {
			return out, nil
		}
		return nil, err
	}
	query := `SELECT id,type,severity,title,body,order_id,read_at,created_at FROM admin_notifications WHERE recipient_user_id=?`
	args := []any{userID}
	if unread {
		query += ` AND read_at IS NULL`
	}
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_notifications WHERE recipient_user_id=?`+unreadPredicate(unread), userID).Scan(&out.Total); err != nil {
		return nil, err
	}
	out.LastPage = (out.Total + perPage - 1) / perPage
	if out.LastPage < 1 {
		out.LastPage = 1
	}
	query += ` ORDER BY id DESC LIMIT ? OFFSET ?`
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, query, args...)
	if err != nil {
		if db.IsMissingSchema(err) {
			return out, nil
		}
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var item Item
		var order sql.NullInt64
		var readAt sql.NullTime
		if err := rows.Scan(&item.ID, &item.Type, &item.Severity, &item.Title, &item.Body, &order, &readAt, &item.CreatedAt); err != nil {
			return nil, err
		}
		if order.Valid {
			item.OrderID = &order.Int64
		}
		if readAt.Valid {
			v := readAt.Time.UTC()
			item.ReadAt = &v
		}
		item.CreatedAt = item.CreatedAt.UTC()
		out.Items = append(out.Items, item)
	}
	return out, rows.Err()
}

func unreadPredicate(unread bool) string {
	if unread {
		return ` AND read_at IS NULL`
	}
	return ""
}

func (s *Store) UnreadCount(ctx context.Context, userID int64) (int, error) {
	var count int
	err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_notifications WHERE recipient_user_id=? AND read_at IS NULL`, userID).Scan(&count)
	if db.IsMissingSchema(err) {
		return 0, nil
	}
	return count, err
}

func (s *Store) MarkRead(ctx context.Context, userID, id int64) (*Item, error) {
	var out Item
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var order sql.NullInt64
		var readAt sql.NullTime
		err := tx.QueryRowContext(ctx, `SELECT id,type,severity,title,body,order_id,read_at,created_at
			FROM admin_notifications WHERE id=? AND recipient_user_id=? FOR UPDATE`, id, userID).
			Scan(&out.ID, &out.Type, &out.Severity, &out.Title, &out.Body, &order, &readAt, &out.CreatedAt)
		if errors.Is(err, sql.ErrNoRows) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		if !readAt.Valid {
			if _, err := tx.ExecContext(ctx, `UPDATE admin_notifications SET read_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND recipient_user_id=?`, id, userID); err != nil {
				return err
			}
			now := time.Now().UTC()
			readAt = sql.NullTime{Time: now, Valid: true}
		}
		if order.Valid {
			out.OrderID = &order.Int64
		}
		if readAt.Valid {
			t := readAt.Time.UTC()
			out.ReadAt = &t
		}
		out.CreatedAt = out.CreatedAt.UTC()
		return nil
	})
	return &out, err
}

func (s *Store) MarkAllRead(ctx context.Context, userID int64) (int64, error) {
	res, err := s.database.ExecContext(ctx, `UPDATE admin_notifications SET read_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE recipient_user_id=? AND read_at IS NULL`, userID)
	if db.IsMissingSchema(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	return res.RowsAffected()
}

// TableReady ports AdminNotification::tableReady(): the notification centre is inert until the
// additive admin_notifications migration exists, so every endpoint degrades to the empty answer
// (index carries migration_pending in its meta) instead of failing. The check is per-call and cheap;
// the reference memoises per-process and this build restarts with migrations in place anyway.
func (s *Store) TableReady(ctx context.Context) bool {
	var count int
	err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_notifications'`).
		Scan(&count)
	return err == nil && count > 0
}
