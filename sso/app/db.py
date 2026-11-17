"""SQLAlchemy engine/session for the SSO database."""
from datetime import datetime, timezone

from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, sessionmaker

from .config import settings

engine = create_engine(settings.db_dsn, pool_pre_ping=True, pool_recycle=3600)
SessionLocal = sessionmaker(bind=engine, expire_on_commit=False)


class Base(DeclarativeBase):
    pass


def utcnow() -> datetime:
    """Naive UTC — matches MySQL DATETIME columns stored in UTC."""
    return datetime.now(timezone.utc).replace(tzinfo=None)
