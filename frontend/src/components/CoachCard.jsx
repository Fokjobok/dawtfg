import { Link } from 'react-router'
import styles from './CoachCard.module.scss'

export default function CoachCard({ coach }) {
  return (
    <Link
      to={`/profiles/${coach.id}`}
      className={`${styles.card} ${
        !coach.avatar_url ? styles.withoutAvatar : ''
      }`}
    >
      <div className={styles.identity}>
        <span className={styles.role}>Coach</span>
        <strong className={styles.name}>{coach.name}</strong>
      </div>

      {coach.avatar_url && (
        <img
          className={styles.avatar}
          src={coach.avatar_url}
          alt=""
          width={112}
          height={112}
          loading="lazy"
          decoding="async"
        />
      )}

      <div className={styles.medals}>
        {coach.medals?.map((medal) => (
          <span key={medal.id} className={styles.medal} title={medal.name}>
            {medal.image_url ? (
              <img
                src={medal.image_url}
                alt={medal.name}
                width={48}
                height={48}
                loading="lazy"
                decoding="async"
              />
            ) : (
              medal.name
            )}
          </span>
        ))}
      </div>
    </Link>
  )
}
